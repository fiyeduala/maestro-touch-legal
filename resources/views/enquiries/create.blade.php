@extends('layouts.public', [
    'title' => $selected ? 'Legal Assistance: '.$selected->name : 'Legal Assistance',
    'description' => 'Tell Maestro Touch Legal what you need help with and a member of our team will respond.',
    'canonical' => $enquiryUrl,
    'noindex' => (bool) $selected,
    'overlayHeader' => true,
])

@section('content')
    @include('pages.partials.banner', ['heading' => 'Legal *Assistance*'])

    <section class="site-container py-16 md:py-20">
        <div class="max-w-[860px]">
            {{-- All wording on this page is a draft for owner approval (docs/content-gaps.md §5). --}}
            @if (! $selected)
                <p>Choose the area you need help with. We will ask a few questions about your matter, then a member of our team will review it and reply by email.</p>

                @if ($services->isEmpty())
                    <p class="mt-8">Online enquiries are not available at the moment. Please use our <a class="text-brand underline" href="{{ url('/contact/') }}">contact page</a>.</p>
                @else
                    <ul class="mt-8 grid gap-4 sm:grid-cols-2">
                        @foreach ($services as $service)
                            <li>
                                <a href="{{ $enquiryUrl.'?service='.urlencode($service->slug) }}" class="block h-full rounded-lg border border-gray-200 p-5 transition hover:border-brand hover:shadow">
                                    <span class="heading-3 block">{{ $service->name }}</span>
                                    @if ($service->summary)
                                        <span class="mt-2 block text-sm">{{ $service->summary }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-8 text-sm">Not sure which to choose? Send a short message from our <a class="text-brand underline" href="{{ url('/contact/') }}">contact page</a>.</p>
                @endif
            @else
                <p><strong class="text-ink">{{ $selected->name }}</strong> · <a class="text-brand underline" href="{{ $enquiryUrl }}">Choose a different area</a></p>
                <p class="mt-4">Fields marked <span class="req">*</span> are required. Please do not send documents yet; if we need them, we will ask through a secure link.</p>

                <div class="mt-8">
                    @include('partials.form-status', ['summary' => true])

                    <form method="post" action="{{ route('enquiry.store') }}" class="space-y-6" novalidate>
                        @csrf
                        <input type="hidden" name="service" value="{{ $selected->slug }}">
                        <div class="hidden" aria-hidden="true">
                            <label for="company_website">Leave this empty</label>
                            <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
                        </div>

                        @include('enquiries.partials.contact-fields', ['organisation' => true])

                        @if ($fields = $selected->publishedForm?->fields)
                            <fieldset class="space-y-6 pt-4">
                                <legend class="heading-3 mb-4">About Your Matter</legend>
                                @foreach ($fields as $field)
                                    @php($id = 'answers_'.$field['key'])
                                    @php($name = 'answers['.$field['key'].']')
                                    @php($value = old('answers.'.$field['key']))
                                    <div>
                                        <label class="form-label" for="{{ $id }}">{{ $field['label'] }} @if (! empty($field['required']))<span class="req">*</span>@endif</label>
                                        @switch($field['type'])
                                            @case('textarea')
                                                <textarea id="{{ $id }}" name="{{ $name }}" rows="4" maxlength="5000" class="form-input" @required(! empty($field['required']))>{{ $value }}</textarea>
                                                @break
                                            @case('select')
                                                <select id="{{ $id }}" name="{{ $name }}" class="form-input" @required(! empty($field['required']))>
                                                    <option value="">Select…</option>
                                                    @foreach ($field['options'] as $option)
                                                        <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                                                    @endforeach
                                                </select>
                                                @break
                                            @case('date')
                                                <input id="{{ $id }}" name="{{ $name }}" type="date" value="{{ $value }}" class="form-input" @required(! empty($field['required']))>
                                                @break
                                            @case('number')
                                                <input id="{{ $id }}" name="{{ $name }}" type="number" min="0" step="any" value="{{ $value }}" class="form-input" @required(! empty($field['required']))>
                                                @break
                                            @default
                                                <input id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" maxlength="500" class="form-input" @required(! empty($field['required']))>
                                        @endswitch
                                        @if (! empty($field['help']))<p class="mt-1 text-sm text-body/80">{{ $field['help'] }}</p>@endif
                                        @error('answers.'.$field['key'])<p class="form-error">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </fieldset>
                        @endif

                        @include('enquiries.partials.summary-consent', ['preferredTimes' => true])

                        <button type="submit" class="btn">Send Enquiry</button>
                    </form>
                </div>
            @endif
        </div>
    </section>
@endsection
