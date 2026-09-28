@if (session('status'))
    <div class="alert alert-success mb-6" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any() && ($summary ?? false))
    <div class="alert alert-error mb-6" role="alert">
        <p class="font-semibold">Please check the form:</p>
        <ul class="mt-1 list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
