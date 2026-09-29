<div style="display:grid;gap:1.25rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))">
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Enquiries received in the period</p>
        <table class="text-sm" style="width:100%">
            <tr class="font-semibold"><td style="padding:.2rem 0">Received</td><td style="text-align:right">{{ $practice['enquiries_received'] }}</td></tr>
            <tr><td style="padding:.2rem 0">Converted to a matter</td><td style="text-align:right">{{ $practice['enquiries_converted'] }}</td></tr>
            @foreach ($practice['enquiries_by_status'] as $label => $count)
                @if ($count)<tr><td style="padding:.2rem 0" class="text-gray-500 dark:text-gray-400">Now {{ strtolower($label) }}</td><td style="text-align:right">{{ $count }}</td></tr>@endif
            @endforeach
        </table>
    </div>
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Time to first staff action</p>
        <table class="text-sm" style="width:100%">
            <tr><td style="padding:.2rem 0">Median</td><td style="text-align:right">{{ $practice['first_action_median_hours'] === null ? '—' : $practice['first_action_median_hours'].' h' }}</td></tr>
            <tr><td style="padding:.2rem 0">90% within</td><td style="text-align:right">{{ $practice['first_action_p90_hours'] === null ? '—' : $practice['first_action_p90_hours'].' h' }}</td></tr>
            <tr @class(['text-danger-600 dark:text-danger-400' => $practice['first_action_missing'] > 0])><td style="padding:.2rem 0">Not yet actioned</td><td style="text-align:right">{{ $practice['first_action_missing'] }}</td></tr>
        </table>
        <p class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:.25rem">Measured from submission to the first status change away from "New" by a staff member. Elapsed clock hours.</p>
    </div>
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Matters and tasks</p>
        <table class="text-sm" style="width:100%">
            <tr><td style="padding:.2rem 0">Matters opened in the period</td><td style="text-align:right">{{ $practice['matters_opened'] }}</td></tr>
            <tr><td style="padding:.2rem 0">Matters closed in the period</td><td style="text-align:right">{{ $practice['matters_closed'] }}</td></tr>
            <tr><td style="padding:.2rem 0">Active now (open or on hold)</td><td style="text-align:right">{{ $practice['matters_active'] }}</td></tr>
            <tr><td style="padding:.2rem 0">Tasks completed in the period</td><td style="text-align:right">{{ $practice['tasks_completed'] }}</td></tr>
            <tr @class(['text-danger-600 dark:text-danger-400' => $practice['tasks_overdue'] > 0])><td style="padding:.2rem 0">Tasks overdue now</td><td style="text-align:right">{{ $practice['tasks_overdue'] }}</td></tr>
        </table>
    </div>
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Consultations in the period</p>
        <table class="text-sm" style="width:100%">
            @forelse ($practice['consultations'] as $status => $count)
                <tr><td style="padding:.2rem 0">{{ ucfirst(str_replace('_', ' ', $status)) }}</td><td style="text-align:right">{{ $count }}</td></tr>
            @empty
                <tr><td style="padding:.2rem 0" class="text-gray-500 dark:text-gray-400">None</td></tr>
            @endforelse
        </table>
    </div>
</div>
