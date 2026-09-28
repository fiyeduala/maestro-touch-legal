<?php

namespace App\Domain\Matters;

use App\Domain\Operations\Audit;
use App\Domain\Operations\StaffNotifier;
use App\Domain\RuleViolation;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Internal matter tasks (spec §7). Tasks are never shown to clients. Assignees must be on the matter
 * team, and dependencies and subtasks stay within one matter.
 */
class Tasks
{
    public function __construct(private Matters $matters) {}

    /**
     * @param  array{title: string, description?: ?string, assignee_id?: ?int, due_at?: ?string, remind_at?: ?string, depends_on_id?: ?int, parent_id?: ?int}  $data
     */
    public function create(Matter $matter, array $data, User $actor): Task
    {
        Gate::forUser($actor)->authorize('manageTasks', $matter);
        $attributes = $this->validated($matter, $data, null);

        $task = DB::transaction(function () use ($matter, $attributes, $actor) {
            $task = $matter->tasks()->create([...$attributes, 'status' => TaskStatus::Open, 'created_by' => $actor->id]);
            $this->matters->event($matter, 'task_created', "Task created: {$task->title}", false, $actor, ['task_id' => $task->id]);
            Audit::record('task.created', "{$matter->reference}: task '{$task->title}' created", $task, actor: $actor);

            return $task;
        });
        $this->notifyAssignee($task, $actor);

        return $task;
    }

    public function update(Task $task, array $data, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $task);
        if (! $task->status->isOpen()) {
            throw new RuleViolation('Completed or cancelled tasks cannot be edited.');
        }
        $previousAssignee = $task->assignee_id;
        $task->fill($this->validated($task->matter, $data + $task->only(['title']), $task));
        $dirty = array_keys($task->getDirty());
        if ($dirty === []) {
            return;
        }
        // A changed due date or reminder schedules fresh reminders and escalation.
        if (in_array('due_at', $dirty, true)) {
            $task->escalated_at = null;
        }
        if (in_array('remind_at', $dirty, true)) {
            $task->reminded_at = null;
        }
        $task->save();
        Audit::record('task.updated', "Task '{$task->title}' updated (".implode(', ', $dirty).')', $task, actor: $actor);
        if ($task->assignee_id !== $previousAssignee) {
            $this->notifyAssignee($task, $actor);
        }
    }

    public function start(Task $task, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $task);
        if ($task->status !== TaskStatus::Open) {
            throw new RuleViolation('Only an open task can be started.');
        }
        $this->assertDependencyDone($task);
        $task->update(['status' => TaskStatus::InProgress]);
        Audit::record('task.started', "Task '{$task->title}' started", $task, actor: $actor);
    }

    public function complete(Task $task, string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $task);
        if (! $task->status->isOpen()) {
            throw new RuleViolation('This task is already finished.');
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new RuleViolation('Add a short completion note.');
        }
        $this->assertDependencyDone($task);
        if ($task->subtasks()->open()->exists()) {
            throw new RuleViolation('Finish or cancel the subtasks first.');
        }

        DB::transaction(function () use ($task, $note, $actor) {
            $task->update(['status' => TaskStatus::Done, 'completed_at' => now(), 'completed_by' => $actor->id, 'completion_note' => trim($note)]);
            $this->matters->event($task->matter, 'task_completed', "Task completed: {$task->title}", false, $actor, ['task_id' => $task->id]);
            Audit::record('task.completed', "Task '{$task->title}' completed", $task, actor: $actor);
        });
    }

    public function cancel(Task $task, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $task);
        if (! $task->status->isOpen()) {
            throw new RuleViolation('This task is already finished.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record why the task is cancelled.');
        }
        if ($task->subtasks()->open()->exists()) {
            throw new RuleViolation('Finish or cancel the subtasks first.');
        }

        DB::transaction(function () use ($task, $reason, $actor) {
            $task->update(['status' => TaskStatus::Cancelled, 'completed_at' => now(), 'completed_by' => $actor->id, 'completion_note' => trim($reason)]);
            $this->matters->event($task->matter, 'task_cancelled', "Task cancelled: {$task->title} ({$reason})", false, $actor, ['task_id' => $task->id]);
            Audit::record('task.cancelled', "Task '{$task->title}' cancelled: ".trim($reason), $task, actor: $actor);
        });
    }

    /**
     * Scheduled (see routes/console.php). Each reminder and escalation is sent once: the timestamp is
     * claimed with a conditional update before the email is queued, so overlapping runs do not duplicate it.
     *
     * @return array{reminded: int, escalated: int}
     */
    public function sendDueNotifications(): array
    {
        $reminded = 0;
        Task::open()->whereNotNull('assignee_id')->whereNotNull('remind_at')->where('remind_at', '<=', now())->whereNull('reminded_at')
            ->with(['assignee', 'matter'])->chunkById(100, function ($tasks) use (&$reminded) {
                foreach ($tasks as $task) {
                    if (! Task::whereKey($task->id)->whereNull('reminded_at')->update(['reminded_at' => now()])) {
                        continue;
                    }
                    if ($task->assignee?->isActive() && $task->matter->isOnTeam($task->assignee)) {
                        $task->assignee->notify(new StaffAlert("Reminder: {$task->title}", "Task reminder for matter {$task->matter->reference}.", "/admin/matters/{$task->matter_id}"));
                        $reminded++;
                    }
                }
            });

        $escalated = 0;
        Task::overdue()->whereNull('escalated_at')->with(['assignee', 'matter.responsible.user'])->chunkById(100, function ($tasks) use (&$escalated) {
            foreach ($tasks as $task) {
                if (! Task::whereKey($task->id)->whereNull('escalated_at')->update(['escalated_at' => now()])) {
                    continue;
                }
                $alert = new StaffAlert("Overdue task on {$task->matter->reference}", "A task on matter {$task->matter->reference} is overdue: {$task->title}.", "/admin/matters/{$task->matter_id}");
                $recipients = collect([$task->assignee, $task->matter->responsible?->user])
                    ->filter(fn (?User $u) => $u?->isActive())->unique('id');
                $recipients->isEmpty()
                    ? StaffNotifier::administrators($alert, 'Overdue task escalation')
                    : StaffNotifier::users($recipients, $alert);
                $this->matters->event($task->matter, 'task_overdue', "Task overdue: {$task->title}", false, null, ['task_id' => $task->id]);
                $escalated++;
            }
        });

        return ['reminded' => $reminded, 'escalated' => $escalated];
    }

    /** @return array<string, mixed> */
    private function validated(Matter $matter, array $data, ?Task $task): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new RuleViolation('The task needs a title.');
        }
        $out = ['title' => mb_substr($title, 0, 255)];

        foreach (['description', 'assignee_id', 'due_at', 'remind_at', 'depends_on_id', 'parent_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = filled($data[$key]) ? $data[$key] : null;
            }
        }

        if (! empty($out['assignee_id'])) {
            $assignee = User::find($out['assignee_id']);
            if (! $assignee || ! $assignee->isActive() || ! $matter->isOnTeam($assignee)) {
                throw new RuleViolation('Tasks can only be assigned to active members of the matter team.');
            }
        }
        foreach (['due_at', 'remind_at'] as $key) {
            if (! empty($out[$key])) {
                $out[$key] = Carbon::parse($out[$key]);
            }
        }
        if (! empty($out['remind_at']) && ! empty($out['due_at']) && $out['remind_at']->gt($out['due_at'])) {
            throw new RuleViolation('The reminder must be before the due date.');
        }

        foreach (['depends_on_id' => 'depend on', 'parent_id' => 'be a subtask of'] as $key => $verb) {
            if (empty($out[$key])) {
                continue;
            }
            $other = Task::find($out[$key]);
            if (! $other || $other->matter_id !== $matter->id || ($task && $other->id === $task->id)) {
                throw new RuleViolation("A task can only {$verb} another task on the same matter.");
            }
        }
        if ($task && ! empty($out['depends_on_id']) && $this->dependsOnTransitively((int) $out['depends_on_id'], $task->id)) {
            throw new RuleViolation('That dependency would create a loop.');
        }
        if ($task && ! empty($out['parent_id']) && $this->hasAncestor((int) $out['parent_id'], $task->id)) {
            throw new RuleViolation('A task cannot be placed under one of its own subtasks.');
        }

        return $out;
    }

    private function dependsOnTransitively(int $startId, int $targetId): bool
    {
        $seen = [];
        for ($id = $startId; $id && ! isset($seen[$id]); $id = (int) Task::whereKey($id)->value('depends_on_id')) {
            if ($id === $targetId) {
                return true;
            }
            $seen[$id] = true;
        }

        return false;
    }

    private function hasAncestor(int $startId, int $targetId): bool
    {
        $seen = [];
        for ($id = $startId; $id && ! isset($seen[$id]); $id = (int) Task::whereKey($id)->value('parent_id')) {
            if ($id === $targetId) {
                return true;
            }
            $seen[$id] = true;
        }

        return false;
    }

    private function assertDependencyDone(Task $task): void
    {
        if ($task->dependsOn && $task->dependsOn->status !== TaskStatus::Done) {
            throw new RuleViolation("This task waits for \"{$task->dependsOn->title}\" to be completed first.");
        }
    }

    private function notifyAssignee(Task $task, User $actor): void
    {
        $assignee = $task->assignee()->first();
        if ($assignee && $assignee->id !== $actor->id && $assignee->isActive()) {
            $assignee->notify(new StaffAlert("New task: {$task->title}", "You have been assigned a task on matter {$task->matter->reference}.", "/admin/matters/{$task->matter_id}"));
        }
    }
}
