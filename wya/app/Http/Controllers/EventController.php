<?php

namespace App\Http\Controllers;

use App\Models\AttendanceStatus;
use App\Models\CancelledEvent;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventSession;
use App\Models\EventStatus;
use App\Models\EventType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'all');

        $cancelledId = $this->statusId('cancelled');

        $createdQuery = Event::query()
            ->with(['eventType', 'status', 'sessions'])
            ->where('creator_id', $user->id)
            ->when($cancelledId, fn ($q) => $q->where('status_id', '!=', $cancelledId));

        $joinedQuery = Event::query()
            ->with(['eventType', 'status', 'sessions'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->where('creator_id', '!=', $user->id)
            ->when($cancelledId, fn ($q) => $q->where('status_id', '!=', $cancelledId));

        $created = $createdQuery->orderByDesc('event_date')->get();
        $joined = $joinedQuery->orderByDesc('event_date')->get();

        $events = match ($filter) {
            'created' => $created,
            'joined' => $joined,
            default => $created->concat($joined)->unique('id')->sortByDesc('event_date')->values(),
        };

        return response()->json([
            'filter' => $filter,
            'events' => $events->map(fn (Event $event) => $this->formatEvent($event, $user)),
        ]);
    }

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_code' => 'required|string|max:8',
        ]);

        $code = strtoupper(trim($validated['event_code']));
        $user = $request->user();

        $event = Event::with(['eventType', 'status', 'sessions'])
            ->where('event_code', $code)
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Invalid event code.'], 404);
        }

        $cancelledId = $this->statusId('cancelled');
        if ($cancelledId && $event->status_id === $cancelledId) {
            return response()->json(['message' => 'This event has been cancelled.'], 422);
        }

        if ($event->creator_id === $user->id) {
            return response()->json(['message' => 'You created this event.'], 422);
        }

        if (EventParticipant::where('event_id', $event->id)->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You have already joined this event.',
                'event' => $this->formatEvent($event, $user),
            ], 409);
        }

        if (! $event->unlimited_capacity && $event->capacity !== null) {
            $count = EventParticipant::where('event_id', $event->id)->count();
            if ($count >= $event->capacity) {
                return response()->json(['message' => 'This event is full.'], 422);
            }
        }

        $registeredId = AttendanceStatus::where('status_name', 'registered')->value('id');
        if (! $registeredId) {
            return response()->json([
                'message' => 'Missing "registered" attendance status. Run AttendanceLookupSeeder.',
            ], 500);
        }

        EventParticipant::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'attendance_status_id' => $registeredId,
            'role' => 'participant',
        ]);

        return response()->json([
            'message' => 'Successfully joined the event!',
            'event' => $this->formatEvent($event->fresh()->load(['eventType', 'status', 'sessions']), $user),
        ], 201);
    }

    public function lookupByCode(Request $request, string $code): JsonResponse
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return response()->json(['message' => 'Invalid event code.'], 404);
        }

        $user = $request->user();

        $event = Event::with(['eventType', 'status', 'sessions'])
            ->where('event_code', $code)
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Invalid event code.'], 404);
        }

        $cancelledId = $this->statusId('cancelled');
        $completedId = $this->statusId('completed');
        $isCreator = $event->creator_id === $user->id;
        $alreadyJoined = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->exists();
        $participantCount = EventParticipant::where('event_id', $event->id)->count();

        $canJoin = true;
        $joinBlockReason = null;

        if ($cancelledId && $event->status_id === $cancelledId) {
            $canJoin = false;
            $joinBlockReason = 'This event has been cancelled.';
        } elseif ($completedId && $event->status_id === $completedId) {
            $canJoin = false;
            $joinBlockReason = 'This event has already ended.';
        } elseif ($isCreator) {
            $canJoin = false;
            $joinBlockReason = 'You created this event.';
        } elseif ($alreadyJoined) {
            $canJoin = false;
        } elseif (! $event->unlimited_capacity && $event->capacity !== null) {
            if ($participantCount >= $event->capacity) {
                $canJoin = false;
                $joinBlockReason = 'This event is full.';
            }
        }

        return response()->json([
            'event' => $this->formatEvent($event, $user),
            'participant_count' => $participantCount,
            'already_joined' => $alreadyJoined,
            'is_creator' => $isCreator,
            'can_join' => $canJoin,
            'join_block_reason' => $joinBlockReason,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $creatorId = $request->user()->id;

        $query = Event::query()
            ->with(['eventType', 'status', 'sessions'])
            ->where('creator_id', $creatorId);

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereYear('event_date', (int) $request->query('year'))
                ->whereMonth('event_date', (int) $request->query('month'));
        }

        if (filter_var($request->query('exclude_cancelled', true), FILTER_VALIDATE_BOOLEAN)) {
            $cancelledId = $this->statusId('cancelled');
            if ($cancelledId) {
                $query->where('status_id', '!=', $cancelledId);
            }
        }

        $events = $query->orderBy('event_date')->get();

        return response()->json([
            'events' => $events->map(fn (Event $event) => $this->formatEvent($event, $request->user())),
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $creatorId = $request->user()->id;
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);

        $cancelledId = $this->statusId('cancelled');

        $events = Event::query()
            ->with(['eventType', 'status'])
            ->where('creator_id', $creatorId)
            ->whereYear('event_date', $year)
            ->whereMonth('event_date', $month)
            ->when($cancelledId, fn ($q) => $q->where('status_id', '!=', $cancelledId))
            ->orderBy('event_date')
            ->get();

        $byDate = [];
        foreach ($events as $event) {
            $dateKey = $event->event_date->format('Y-m-d');
            $byDate[$dateKey] ??= [];
            $byDate[$dateKey][] = $this->formatEventSummary($event);
        }

        return response()->json([
            'month' => $month,
            'year' => $year,
            'dates' => array_keys($byDate),
            'events_by_date' => $byDate,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $creatorId = $request->user()->id;

        $events = Event::query()
            ->with(['eventType', 'status', 'sessions', 'cancellation'])
            ->where('creator_id', $creatorId)
            ->orderByDesc('event_date')
            ->get();

        return response()->json([
            'events' => $events->map(fn (Event $event) => $this->formatEvent($event, $request->user())),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $event = Event::with(['eventType', 'status', 'sessions', 'cancellation'])
            ->findOrFail($id);

        return response()->json([
            'event' => $this->formatEvent($event, $request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_name' => 'required|string|max:255',
            'event_location' => 'required|string|max:255',
            'event_date' => 'required|date',
            'event_type_id' => 'nullable|integer|exists:event_types,id',
            'event_type' => 'nullable|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'unlimited_capacity' => 'boolean',
            'allow_late_checkin' => 'boolean',
            'auto_mark_absent' => 'boolean',
            'join_policy' => 'nullable|string|max:50',
            'banner_image' => 'nullable|string|max:255',
            'sessions' => 'nullable|array',
            'sessions.*.session_label' => 'required_with:sessions|string|max:255',
            'sessions.*.time_in' => 'nullable|string',
            'sessions.*.time_out' => 'nullable|string',
            'sessions.*.start_datetime' => 'nullable|date',
            'sessions.*.end_datetime' => 'nullable|date',
        ]);

        $eventTypeId = $this->resolveEventTypeId(
            $validated['event_type_id'] ?? null,
            $validated['event_type'] ?? null
        );

        if (! $eventTypeId) {
            return response()->json([
                'message' => 'Event type is required. Send event_type_id or event_type (e.g. "Whole day").',
            ], 422);
        }

        $upcomingId = $this->statusId('upcoming');
        if (! $upcomingId) {
            return response()->json([
                'message' => 'Missing "upcoming" status. Run: php artisan db:seed --class=EventLookupSeeder',
            ], 500);
        }

        $user = $request->user();

        $event = DB::transaction(function () use ($validated, $eventTypeId, $upcomingId, $user) {
            $event = Event::create([
                'creator_id' => $user->id,
                'event_type_id' => $eventTypeId,
                'status_id' => $upcomingId,
                'event_code' => $this->generateEventCode(),
                'event_name' => $validated['event_name'],
                'event_location' => $validated['event_location'],
                'event_date' => $validated['event_date'],
                'capacity' => ($validated['unlimited_capacity'] ?? false) ? null : ($validated['capacity'] ?? null),
                'unlimited_capacity' => $validated['unlimited_capacity'] ?? false,
                'allow_late_checkin' => $validated['allow_late_checkin'] ?? false,
                'auto_mark_absent' => $validated['auto_mark_absent'] ?? false,
                'join_policy' => $validated['join_policy'] ?? 'open',
                'banner_image' => $validated['banner_image'] ?? null,
            ]);

            $this->syncSessions($event, $validated['sessions'] ?? [], $validated['event_date']);

            return $event->load(['eventType', 'status', 'sessions']);
        });

        return response()->json([
            'message' => 'Event created successfully',
            'event' => $this->formatEvent($event, $user),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::with('sessions')->findOrFail($id);
        $this->authorizeCreator($request, $event);

        $validated = $request->validate([
            'event_name' => 'sometimes|string|max:255',
            'event_location' => 'sometimes|string|max:255',
            'event_date' => 'sometimes|date',
            'event_type_id' => 'nullable|integer|exists:event_types,id',
            'event_type' => 'nullable|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'unlimited_capacity' => 'boolean',
            'allow_late_checkin' => 'boolean',
            'auto_mark_absent' => 'boolean',
            'join_policy' => 'nullable|string|max:50',
            'banner_image' => 'nullable|string|max:255',
            'sessions' => 'nullable|array',
            'sessions.*.session_label' => 'required_with:sessions|string|max:255',
            'sessions.*.time_in' => 'nullable|string',
            'sessions.*.time_out' => 'nullable|string',
            'sessions.*.start_datetime' => 'nullable|date',
            'sessions.*.end_datetime' => 'nullable|date',
        ]);

        if (isset($validated['event_type_id']) || isset($validated['event_type'])) {
            $typeId = $this->resolveEventTypeId(
                $validated['event_type_id'] ?? null,
                $validated['event_type'] ?? null
            );
            if ($typeId) {
                $validated['event_type_id'] = $typeId;
            }
        }

        DB::transaction(function () use ($event, $validated, $request) {
            $eventDate = $validated['event_date'] ?? $event->event_date->format('Y-m-d');

            if (array_key_exists('unlimited_capacity', $validated) && $validated['unlimited_capacity']) {
                $validated['capacity'] = null;
            }

            $event->update(collect($validated)->only([
                'event_name',
                'event_location',
                'event_date',
                'event_type_id',
                'capacity',
                'unlimited_capacity',
                'allow_late_checkin',
                'auto_mark_absent',
                'join_policy',
                'banner_image',
            ])->filter(fn ($v) => $v !== null)->all());

            if ($request->has('sessions')) {
                $event->sessions()->delete();
                $this->syncSessions($event, $validated['sessions'] ?? [], $eventDate);
            }
        });

        $event->refresh()->load(['eventType', 'status', 'sessions']);

        return response()->json([
            'message' => 'Event updated successfully',
            'event' => $this->formatEvent($event, $request->user()),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $this->authorizeCreator($request, $event);

        $cancelledId = $this->statusId('cancelled');
        if (! $cancelledId) {
            return response()->json([
                'message' => 'Missing "cancelled" status. Run: php artisan db:seed --class=EventLookupSeeder',
            ], 500);
        }

        if ($event->status_id === $cancelledId) {
            return response()->json([
                'message' => 'Event is already cancelled',
                'event' => $this->formatEvent($event->load(['eventType', 'status', 'sessions'])),
            ], 409);
        }

        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($event, $cancelledId, $validated, $request) {
            $event->update(['status_id' => $cancelledId]);

            CancelledEvent::updateOrCreate(
                ['event_id' => $event->id],
                [
                    'cancelled_by' => $request->user()->id,
                    'cancellation_reason' => $validated['cancellation_reason'] ?? 'Cancelled by organizer',
                ]
            );
        });

        $event->refresh()->load(['eventType', 'status', 'sessions', 'cancellation']);

        return response()->json([
            'message' => 'Event cancelled successfully',
            'event' => $this->formatEvent($event, $request->user()),
        ]);
    }

    public function types(): JsonResponse
    {
        return response()->json([
            'event_types' => EventType::orderBy('type_name')->get(['id', 'type_name']),
        ]);
    }

    private function resolveEventTypeId(?int $typeId, ?string $typeName): ?int
    {
        if ($typeId) {
            return $typeId;
        }

        if ($typeName) {
            $type = EventType::firstOrCreate(['type_name' => trim($typeName)]);

            return $type->id;
        }

        return null;
    }

    private function statusId(string $name): ?int
    {
        return EventStatus::where('status_name', $name)->value('id');
    }

    private function generateEventCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Event::where('event_code', $code)->exists());

        return $code;
    }

    private function authorizeCreator(Request $request, Event $event): void
    {
        if ($event->creator_id !== $request->user()->id) {
            abort(403, 'You can only manage events you created.');
        }
    }

    private function syncSessions(Event $event, array $sessions, string $eventDate): void
    {
        foreach ($sessions as $index => $session) {
            $label = $session['session_label'] ?? ('Session '.($index + 1));

            EventSession::create([
                'event_id' => $event->id,
                'session_label' => $label,
                'start_datetime' => $this->resolveSessionDatetime(
                    $session,
                    $eventDate,
                    'start_datetime',
                    'time_in'
                ),
                'end_datetime' => $this->resolveSessionDatetime(
                    $session,
                    $eventDate,
                    'end_datetime',
                    'time_out'
                ),
            ]);
        }
    }

    private function resolveSessionDatetime(
        array $session,
        string $eventDate,
        string $datetimeKey,
        string $timeKey
    ): ?string {
        if (! empty($session[$datetimeKey])) {
            return Carbon::parse($session[$datetimeKey])->toDateTimeString();
        }

        if (! empty($session[$timeKey])) {
            $time = $session[$timeKey];
            if (strlen($time) <= 5) {
                $time .= ':00';
            }

            return Carbon::parse("{$eventDate} {$time}")->toDateTimeString();
        }

        return null;
    }

    private function formatEventSummary(Event $event): array
    {
        return [
            'id' => $event->id,
            'event_name' => $event->event_name,
            'event_location' => $event->event_location,
            'event_date' => $event->event_date->format('Y-m-d'),
            'status' => $event->status?->status_name ?? 'upcoming',
            'event_type' => $event->eventType?->type_name,
        ];
    }

    private function formatEvent(Event $event, $user = null): array
    {
        $sessions = $event->sessions->map(fn (EventSession $s) => [
            'id' => $s->id,
            'session_label' => $s->session_label,
            'start_datetime' => $s->start_datetime?->toIso8601String(),
            'end_datetime' => $s->end_datetime?->toIso8601String(),
            'time_in' => $s->start_datetime?->format('H:i'),
            'time_out' => $s->end_datetime?->format('H:i'),
        ])->values();

        $isCreator = $user && $event->creator_id === $user->id;
        $isJoined = $user && ! $isCreator && EventParticipant::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->exists();

        return [
            'id' => $event->id,
            'creator_id' => $event->creator_id,
            'event_code' => $event->event_code,
            'is_creator' => $isCreator,
            'is_joined' => $isJoined,
            'event_name' => $event->event_name,
            'event_location' => $event->event_location,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_type_id' => $event->event_type_id,
            'event_type' => $event->eventType?->type_name,
            'status_id' => $event->status_id,
            'status' => $event->status?->status_name ?? 'upcoming',
            'capacity' => $event->capacity,
            'unlimited_capacity' => $event->unlimited_capacity,
            'allow_late_checkin' => $event->allow_late_checkin,
            'auto_mark_absent' => $event->auto_mark_absent,
            'join_policy' => $event->join_policy,
            'banner_image' => $event->banner_image,
            'sessions' => $sessions,
            'cancellation' => $event->cancellation ? [
                'cancellation_reason' => $event->cancellation->cancellation_reason,
                'cancelled_at' => $event->cancellation->created_at?->toIso8601String(),
            ] : null,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }
}
