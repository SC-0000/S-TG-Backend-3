<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Complete your profile</title>
  <style>
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; padding: 24px; background:#f7fafc; }
    .card { background: #fff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; max-width:900px; margin:0 auto; }
    label { display:block; margin-bottom:8px; font-weight:600; }
    input[type="text"], input[type="email"], input[type="date"] { width:100%; padding:8px 10px; border:1px solid #cbd5e0; border-radius:6px; margin-bottom:12px; }
    .children { margin-top:16px; }
    .child-row { border:1px dashed #e2e8f0; padding:10px; margin-bottom:10px; border-radius:6px; background:#fbfbfb; position:relative; }
    .child-row .remove { position:absolute; right:8px; top:8px; background:#fed7d7; border:0; padding:6px 8px; border-radius:6px; cursor:pointer; }
    .actions { display:flex; gap:8px; margin-top:16px; }
    button { background:#2563eb; color:#fff; border:0; padding:10px 14px; border-radius:8px; cursor:pointer; }
    .secondary { background:#e2e8f0; color:#111; }
    .hint { color:#666; font-size:13px; margin-bottom:12px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Complete your profile</h1>
    <p class="hint">To access this page you need to finish a short registration. Provide an email and any child details you want to keep. After submitting we'll upgrade your account to a full parent account.</p>

    <form method="POST" action="{{ route('guest.complete_profile.store') }}">
      @csrf

      <input type="hidden" name="redirect_to" value="{{ old('redirect_to', $redirect_to ?? '') }}">

      <label for="name">Parent name (optional)</label>
      <input id="name" name="name" type="text" value="{{ old('name', $user->name ?? '') }}" placeholder="Full name">

      <label for="email">Email (required)</label>
      <input id="email" name="email" type="email" value="{{ old('email', $user->email ?? '') }}" required placeholder="you@example.com">

      <div class="children">
        <h3>Children</h3>
        <div id="children-container">
          @php $i = 0; @endphp
          @foreach($children as $child)
            <div class="child-row" data-index="{{ $i }}">
              <input type="hidden" name="children[{{ $i }}][id]" value="{{ $child->id }}">
              <label>Child name
                <input name="children[{{ $i }}][child_name]" type="text" required value="{{ old("children.$i.child_name", $child->child_name) }}">
              </label>
              <label>Date of birth
                <input name="children[{{ $i }}][date_of_birth]" type="date" value="{{ old("children.$i.date_of_birth", optional($child->date_of_birth)->format('Y-m-d')) }}">
              </label>
              <button type="button" class="remove" onclick="removeRow(this)">Remove</button>
            </div>
            @php $i++; @endphp
          @endforeach

          @if(old('children'))
            @foreach(old('children') as $oi => $oc)
              @continue(isset($children[$oi]))
              <div class="child-row" data-index="{{ $i }}">
                <input type="hidden" name="children[{{ $i }}][id]" value="">
                <label>Child name
                  <input name="children[{{ $i }}][child_name]" type="text" required value="{{ $oc['child_name'] ?? '' }}">
                </label>
                <label>Date of birth
                  <input name="children[{{ $i }}][date_of_birth]" type="date" value="{{ $oc['date_of_birth'] ?? '' }}">
                </label>
                <button type="button" class="remove" onclick="removeRow(this)">Remove</button>
              </div>
              @php $i++; @endphp
            @endforeach
          @endif
        </div>

        <div class="actions">
          <button type="button" onclick="addChildRow()">+ Add child</button>
          <button type="button" class="secondary" onclick="clearNewRows()">Clear new rows</button>
        </div>
      </div>

      <div class="actions">
        <button type="submit">Complete registration</button>
        <a href="{{ url()->previous() }}" class="secondary" style="display:inline-block;padding:10px 14px;border-radius:8px;text-decoration:none;color:#111;">Cancel</a>
      </div>

      @if($errors->any())
        <div style="margin-top:12px;color:#b91c1c;">
          <strong>Errors:</strong>
          <ul>
            @foreach($errors->all() as $err)
              <li>{{ $err }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @if(session('success'))
        <div style="margin-top:12px;color:#15803d;font-weight:600;">
          {{ session('success') }}
        </div>
      @endif
    </form>
  </div>

  <script>
    function reindexRows() {
      const container = document.getElementById('children-container');
      const rows = Array.from(container.querySelectorAll('.child-row'));
      rows.forEach((r, i) => {
        r.setAttribute('data-index', i);
        const idInput = r.querySelector('input[type="hidden"][name*="[id]"]');
        if (idInput) idInput.name = `children[${i}][id]`;
        const nameInput = r.querySelector('input[name*="[child_name]"]');
        if (nameInput) nameInput.name = `children[${i}][child_name]`;
        const dobInput = r.querySelector('input[name*="[date_of_birth]"]');
        if (dobInput) dobInput.name = `children[${i}][date_of_birth]`;
      });
    }

    function addChildRow() {
      const container = document.getElementById('children-container');
      const index = container.children.length;
      const row = document.createElement('div');
      row.className = 'child-row';
      row.setAttribute('data-index', index);

      row.innerHTML = `
        <input type="hidden" name="children[${index}][id]" value="">
        <label>Child name
          <input name="children[${index}][child_name]" type="text" required>
        </label>
        <label>Date of birth
          <input name="children[${index}][date_of_birth]" type="date">
        </label>
        <button type="button" class="remove" onclick="removeRow(this)">Remove</button>
      `;
      container.appendChild(row);
    }

    function removeRow(button) {
      const row = button.closest('.child-row');
      if (row) row.remove();
      reindexRows();
    }

    function clearNewRows() {
      const container = document.getElementById('children-container');
      const rows = Array.from(container.querySelectorAll('.child-row'));
      rows.forEach((r) => {
        const idInput = r.querySelector('input[type="hidden"][name*="[id]"]');
        if (!idInput || idInput.value === '') {
          r.remove();
        }
      });
      reindexRows();
    }
  </script>
</body>
</html>
