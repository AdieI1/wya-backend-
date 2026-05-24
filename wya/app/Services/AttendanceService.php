<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendanceStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceService
{
    public const PRESENT_GRACE_MINUTES = 30;

    public const INCOMPLETE_HOURS = 2;

    public function resolveEventPhase(Event $event): string
    {
        if ($event->status?->status_name === 'cancelled') {
            return 'cancelled';
        }

        if ($event->status?->status_name === 'completed') {
            return 'completed';
        }

        $sessions = $event->sessions ?? collect();

        if ($sessions->isEmpty()) {
            $start = Carbon::parse($event->event_date)->startOfDay();
            $end = $start->copy()->endOfDay();

            if (now()->lt($start)) {
                return 'upcoming';
            }
            if (now()->lte($end)) {
                return 'ongoing';
            }

            return 'completed';
        }

        $starts = $sessions->pluck('start_datetime')->filter();
        $ends = $sessions->pluck('end_datetime')->filter();

        if ($starts->isEmpty()) {
            return 'upcoming';
        }

        $firstStart = $starts->min();
        $lastEnd = $ends->max() ?? $starts->max();

        if (now()->lt($firstStart)) {
            return 'upcoming';
        }
        if (now()->lte($lastEnd)) {
            return 'ongoing';
        }

        return 'completed';
    }

    public function canTimeIn(EventSession $session, ?AttendanceLog $log): array
    {
        $now = now();
        $start = $session->start_datetime;

        if (! $start) {
            return ['allowed' => false, 'reason' => 'Session has no start time.'];
        }

        if ($log?->time_in) {
            return ['allowed' => false, 'reason' => 'You have already timed in for this session.'];
        }

        if ($now->lt($start)) {
            return [
                'allowed' => false,
                'reason' => 'Time in opens at '.$start->format('g:i A').'.',
            ];
        }

        $end = $session->end_datetime ?? $start;
        if ($now->gt($end)) {
            return ['allowed' => false, 'reason' => 'This session has already ended.'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function canTimeOut(EventSession $session, ?AttendanceLog $log): array
    {
        $now = now();
        $end = $session->end_datetime ?? $session->start_datetime;

        if (! $log?->time_in) {
            return ['allowed' => false, 'reason' => 'You must time in first.'];
        }

        if ($log->time_out) {
            return ['allowed' => false, 'reason' => 'You have already timed out for this session.'];
        }

        if (! $end) {
            return ['allowed' => false, 'reason' => 'Session has no end time.'];
        }

        if ($now->lt($end)) {
            return [
                'allowed' => false,
                'reason' => 'Time out opens at '.$end->format('g:i A').'.',
                'opens_at' => $end->toIso8601String(),
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function checkInStatus(Carbon $timeIn, Carbon $sessionStart): string
    {
        $minutesAfterStart = $sessionStart->diffInMinutes($timeIn, false);

        if ($minutesAfterStart <= self::PRESENT_GRACE_MINUTES) {
            return 'present';
        }

        return 'late';
    }

    public function resolveSessionStatus(
        EventSession $session,
        ?AttendanceLog $log,
        ?Carbon $now = null
    ): string {
        $now = $now ?? now();
        $start = $session->start_datetime;
        $end = $session->end_datetime ?? $start;

        if (! $log?->time_in) {
            if ($end && $now->gt($end)) {
                return 'absent';
            }

            return 'pending';
        }

        if (! $log->time_out) {
            $incompleteAfter = ($end ?? $log->time_in)->copy()->addHours(self::INCOMPLETE_HOURS);
            if ($now->gt($incompleteAfter)) {
                return 'incomplete';
            }

            if ($end && $now->gt($end->copy()->addHours(self::INCOMPLETE_HOURS))) {
                return 'incomplete';
            }

            return $this->checkInStatus($log->time_in, $start ?? $log->time_in);
        }

        return $this->checkInStatus($log->time_in, $start ?? $log->time_in);
    }

    public function rollupStatuses(array $statuses): string
    {
        $priority = [
            'absent' => 5,
            'incomplete' => 4,
            'late' => 3,
            'present' => 2,
            'pending' => 1,
            'registered' => 0,
        ];

        $best = 'registered';
        $bestScore = -1;

        foreach ($statuses as $status) {
            $score = $priority[$status] ?? 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $status;
            }
        }

        return $best;
    }

    public function syncParticipantStatus(EventParticipant $participant, Event $event): void
    {
        $event->loadMissing('sessions');
        $logs = AttendanceLog::where('participant_id', $participant->id)
            ->get()
            ->keyBy('session_id');

        $statuses = [];
        foreach ($event->sessions as $session) {
            $statuses[] = $this->resolveSessionStatus(
                $session,
                $logs->get($session->id)
            );
        }

        if ($statuses === []) {
            return;
        }

        $rolledUp = $this->rollupStatuses($statuses);
        if ($rolledUp === 'pending' || $rolledUp === 'registered') {
            return;
        }

        $statusId = AttendanceStatus::where('status_name', $rolledUp)->value('id');
        if ($statusId) {
            $participant->update(['attendance_status_id' => $statusId]);
        }
    }

    public function formatLogForSession(
        EventSession $session,
        ?AttendanceLog $log,
        Event $event
    ): array {
        $phase = $this->resolveEventPhase($event);
        $status = $this->resolveSessionStatus($session, $log);
        $canTimeIn = $this->canTimeIn($session, $log);
        $canTimeOut = $this->canTimeOut($session, $log);

        return [
            'session_id' => $session->id,
            'session_label' => $session->session_label,
            'start_datetime' => $session->start_datetime?->toIso8601String(),
            'end_datetime' => $session->end_datetime?->toIso8601String(),
            'time_in' => $session->start_datetime?->format('g:i A'),
            'time_out' => $session->end_datetime?->format('g:i A'),
            'attendance' => [
                'log_id' => $log?->id,
                'time_in' => $log?->time_in?->toIso8601String(),
                'time_out' => $log?->time_out?->toIso8601String(),
                'time_in_display' => $log?->time_in?->format('g:i A'),
                'time_out_display' => $log?->time_out?->format('g:i A'),
                'status' => $status,
                'can_time_in' => $phase !== 'cancelled' && $canTimeIn['allowed'],
                'can_time_out' => $phase !== 'cancelled' && $canTimeOut['allowed'],
                'time_out_opens_at' => $canTimeOut['opens_at'] ?? $session->end_datetime?->toIso8601String(),
                'time_in_block_reason' => $canTimeIn['reason'],
                'time_out_block_reason' => $canTimeOut['reason'],
                'is_active' => $phase === 'ongoing'
                    && $session->start_datetime
                    && $session->end_datetime
                    && now()->between($session->start_datetime, $session->end_datetime),
            ],
        ];
    }

    public function countStatuses(Event $event, Collection $participants): array
    {
        $event->loadMissing('sessions');
        $counts = [
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'incomplete' => 0,
        ];

        $phase = $this->resolveEventPhase($event);

        if ($phase === 'upcoming') {
            return $counts;
        }

        foreach ($participants as $participant) {
            $logs = $participant->attendanceLogs->keyBy('session_id');
            $sessionStatuses = [];

            foreach ($event->sessions as $session) {
                $sessionStatuses[] = $this->resolveSessionStatus(
                    $session,
                    $logs->get($session->id)
                );
            }

            $overall = $this->rollupStatuses($sessionStatuses ?: ['pending']);
            if (isset($counts[$overall])) {
                $counts[$overall]++;
            }
        }

        return $counts;
    }

    public function formatParticipantRow(
        EventParticipant $participant,
        Event $event,
        string $phase
    ): array {
        $user = $participant->user;
        $logs = $participant->attendanceLogs->keyBy('session_id');

        $sessions = $event->sessions->map(function (EventSession $session) use ($logs, $phase, $event) {
            $log = $logs->get($session->id);
            $status = $this->resolveSessionStatus($session, $log);

            if ($phase === 'upcoming') {
                return [
                    'session_id' => $session->id,
                    'time_in_display' => null,
                    'time_out_display' => null,
                    'time_in_status' => null,
                    'time_out_status' => null,
                ];
            }

            return [
                'session_id' => $session->id,
                'time_in_display' => $log?->time_in?->format('g:i A'),
                'time_out_display' => $log?->time_out?->format('g:i A'),
                'time_in_status' => $log?->time_in ? $status : ($phase === 'completed' ? 'absent' : null),
                'time_out_status' => $log?->time_out ? $status : null,
            ];
        });

        $sessionStatuses = $event->sessions->map(
            fn (EventSession $session) => $this->resolveSessionStatus(
                $session,
                $logs->get($session->id)
            )
        )->all();

        $overall = $phase === 'upcoming'
            ? 'pending'
            : $this->rollupStatuses($sessionStatuses ?: ['pending']);

        $primarySession = $event->sessions->first();
        $primaryLog = $primarySession ? $logs->get($primarySession->id) : null;

        return [
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'overall_status' => $overall,
            'time_in_display' => $phase === 'upcoming' ? null : ($primaryLog?->time_in?->format('g:i A')),
            'time_out_display' => $phase === 'upcoming' ? null : ($primaryLog?->time_out?->format('g:i A')),
            'time_in_status' => $primaryLog?->time_in
                ? $this->resolveSessionStatus($primarySession, $primaryLog)
                : ($phase === 'completed' ? 'absent' : null),
            'time_out_status' => $primaryLog?->time_out
                ? $this->resolveSessionStatus($primarySession, $primaryLog)
                : null,
            'time_in_sort' => $primaryLog?->time_in?->timestamp,
            'sessions' => $sessions->values(),
        ];
    }
}
