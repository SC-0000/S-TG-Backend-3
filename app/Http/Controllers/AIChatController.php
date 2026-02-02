<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use OpenAI\Client;
use App\Models\Faq;
use App\Models\Article;
use Carbon\Carbon;

class AIChatController extends Controller
{
    public function __construct(private Client $openAI) {}

    public function ask(Request $request)
    {
        $data = $request->validate([
            'prompt'  => 'required|string|max:1000',
            'history' => 'nullable|array',
        ]);

        $prompt  = $data['prompt'];
        $history = $data['history'] ?? [];

        /* ── build primer (cached) ───────────────────────── */
        $primer = $this->buildKnowledgePrimer();

        /* ── compose messages ────────────────────────────── */
        $messages = [
            ['role'=>'system','content'=>'You are the public FAQ bot for 11 Plus Tutor …'],
            ['role'=>'system','content'=>$primer],
        ];
        foreach ($history as $t) if(isset($t['role'],$t['content'])) $messages[]=$t;
        $messages[] = ['role'=>'user','content'=>$prompt];

        /* ── summarise old turns (>20) ───────────────────── */
        $messages = $this->compressHistory($messages);

        /* ── call OpenAI for the reply ───────────────────── */
        $resp  = $this->openAI->chat()->create([
            'model'=>'gpt-5-nano','messages'=>$messages,
        ]);
        $reply = $resp['choices'][0]['message']['content'];

        /* ── extend history returned to browser ──────────── */
        $history[] = ['role'=>'user','content'=>$prompt];
        $history[] = ['role'=>'assistant','content'=>$reply];

        /* ── lightweight log only ───────────────────────── */
        Log::channel('chatbot')->info('PublicBot', [
            'prompt'=>$prompt,
            'reply_preview'=>mb_substr($reply,0,120),
            'ip'=>$request->ip(),
            'ua'=>$request->userAgent(),
        ]);

        return response()->json(['reply'=>$reply,'history'=>$history]);
    }

    /* ------- safe primer builder (no crashes) ----------- */
  private function buildKnowledgePrimer(): string
{
    /* -------------------------------------------------- *
     *  Cache key = latest updated_at across FAQs/Articles
     * -------------------------------------------------- */
   $faqRaw = Faq::where('published',1)->max('updated_at');          // → string|null
    $artRaw = Article::where('scheduled_publish_date','<=',now())
                     ->max('updated_at');                            // → string|null

    $faqStamp = $faqRaw ? Carbon::parse($faqRaw)->timestamp : 0;
    $artStamp = $artRaw ? Carbon::parse($artRaw)->timestamp : 0;

    $stamp = max($faqStamp, $artStamp);   // 0 if both tables empty

    return Cache::remember("kb-primer:$stamp", 600, function () {

        /* 1️⃣  Fetch published FAQs ---------------------------------- */
        $faqs = Faq::where('published',1)
                   ->select('question','answer','category','tags')
                   ->orderBy('updated_at','desc')
                   ->get();

        /* 2️⃣  Fetch articles that are already live ------------------ */
        $articles = Article::where('scheduled_publish_date','<=',now())
                   ->select('title','description','id',
                            'body_type','bodies','sections')
                   ->latest('scheduled_publish_date')
                   ->take(10)                                      // last 10 is enough
                   ->get();

        /* 3️⃣  Build raw knowledge text ----------------------------- */
        $txt = "FAQs:\n";
        foreach ($faqs as $f) {
            $tags = $f->tags ? implode(', ',$f->tags) : '';
            $txt .= "- Q: {$f->question}\n"
                  . "  A: {$f->answer}\n"
                  . ($tags ? "  Tags: $tags\n" : '')
                  . "\n";
        }

        $txt .= "\nArticles:\n";
        foreach ($articles as $a) {
            $body = $this->extractArticleBody($a);
            $txt .= "- {$a->title}: {$body}\n"
                  . "(link: /articles/{$a->id})\n\n";
        }

        /* 4️⃣  Summarise if >4 000 chars ----------------------------- */
        if (mb_strlen($txt) > 4_000) {
            $txt = $this->summariseText(
                $txt,
                'Summarise these FAQs and articles in ≤600 words; keep URLs.'
            );
        }

        return "Knowledge base below.\n$txt";
    });
}

/* helper – pull the most useful text out of an article record */
private function extractArticleBody(Article $a): string
{
    // Prefer description; else first body paragraph
    if (!empty($a->description)) return $a->description;

    if ($a->body_type === 'template' && is_array($a->bodies)) {
        return strip_tags($a->bodies[0] ?? '');
    }

    // PDF articles: fall back to title only (bot can link user)
    return '';
}


    private function summariseText(string $blob, string $sys): string
    {
        $r = $this->openAI->chat()->create([
            'model'=>'gpt-5-nano',
            'messages'=>[
                ['role'=>'system','content'=>$sys],
                ['role'=>'user','content'=>$blob],
            ]
        ]);
        return $r['choices'][0]['message']['content'];
    }

    private function compressHistory(array $msgs): array
    {
        $turns = array_values(array_filter($msgs,
                 fn($m)=>in_array($m['role'],['user','assistant'])));
        if (count($turns)/2 <= 20) return $msgs;

        $segment = array_slice($msgs,2,20);
        $summary = $this->summariseText(
            implode("\n",array_column($segment,'content')),
            'Summarise this dialogue in ≤120 words.');
        return array_merge(
            array_slice($msgs,0,2),
            [['role'=>'system','content'=>"Summary so far: $summary"]],
            array_slice($msgs,22)
        );
    }
}
