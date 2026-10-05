{{-- Spam screening (App\Support\FormGuard): a field only bots fill, and when the form was shown. --}}
<div class="hidden" aria-hidden="true">
    <label for="company_website">Leave this empty</label>
    <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
</div>
<input type="hidden" name="form_started" value="{{ \App\Support\FormGuard::stamp() }}">
