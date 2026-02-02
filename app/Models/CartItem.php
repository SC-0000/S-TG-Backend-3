<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'service_id',
        'product_id',   // new
        'quantity',
        'price',
        'metadata',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** 
     * Returns whichever “buyable” is set 
     */
    public function getBuyableAttribute()
    {
        return $this->service ?? $this->product;
    }

    public function getBuyableTypeAttribute()
    {
        return $this->service
            ? Service::class
            : Product::class;
    }

    public function getBuyableIdAttribute()
    {
        return $this->service_id ?: $this->product_id;
    }

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Validate user selections for flexible services
     */
    public function validateSelections(Service $service): bool
    {
        if (!$service->isFlexibleService()) {
            return true; // No validation needed for non-flexible services
        }
        
        $required = $service->getRequiredSelections();
        $selected = $this->metadata ?? [];
        
        // Check live sessions count
        $selectedSessions = $selected['selected_live_sessions'] ?? [];
        if (count($selectedSessions) !== $required['live_sessions']) {
            throw new \Exception("Must select exactly {$required['live_sessions']} live sessions");
        }
        
        // Check assessments count
        $selectedAssessments = $selected['selected_assessments'] ?? [];
        if (count($selectedAssessments) !== $required['assessments']) {
            throw new \Exception("Must select exactly {$required['assessments']} assessments");
        }
        
        // Validate availability of selected live sessions
        $availableSessions = $service->getAvailableLiveSessions()
            ->filter(fn($s) => $s->is_available)
            ->pluck('id')
            ->toArray();
            
        foreach ($selectedSessions as $sessionId) {
            if (!in_array($sessionId, $availableSessions)) {
                throw new \Exception("Selected live session $sessionId is no longer available");
            }
        }
        
        // Validate availability of selected assessments
        $availableAssessments = $service->getAvailableAssessments()
            ->filter(fn($a) => $a->is_available)
            ->pluck('id')
            ->toArray();
            
        foreach ($selectedAssessments as $assessmentId) {
            if (!in_array($assessmentId, $availableAssessments)) {
                throw new \Exception("Selected assessment $assessmentId is no longer available");
            }
        }
        
        return true;
    }

    /**
     * Get selected content IDs
     * Note: Frontend sends 'selected_lessons', backend uses 'live_sessions'
     */
    public function getSelectedContent(): array
    {
        $metadata = $this->metadata ?? [];
        
        // Support both 'selected_lessons' (frontend) and 'selected_live_sessions' (legacy)
        $lessons = $metadata['selected_lessons'] 
            ?? $metadata['selected_live_sessions'] 
            ?? [];
            
        $assessments = $metadata['selected_assessments'] ?? [];
        
        return [
            'live_sessions' => $lessons,
            'assessments' => $assessments,
        ];
    }
}
