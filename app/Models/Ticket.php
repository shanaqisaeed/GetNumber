<?php

namespace App\Models;

use App\Enums\TicketDepartment;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'user_id',
        'assigned_to',
        'phone_number_id',
        'department',
        'priority',
        'status',
        'subject',
        'last_reply_at',
    ];

    protected function casts(): array
    {
        return [
            'department' => TicketDepartment::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'last_reply_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = self::generateUniqueTicketNumber();
            }
            if (empty($ticket->last_reply_at)) {
                $ticket->last_reply_at = now();
            }
        });
    }

    /**
     * Generate unique ticket reference code, e.g. TK-261024-A1B2
     */
    public static function generateUniqueTicketNumber(): string
    {
        do {
            $code = 'TK-' . date('ymd') . '-' . strtoupper(Str::random(4));
        } while (self::where('ticket_number', $code)->exists());

        return $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at', 'asc');
    }

    public function attachments(): HasManyThrough
    {
        return $this->hasManyThrough(TicketAttachment::class, TicketMessage::class);
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }
}
