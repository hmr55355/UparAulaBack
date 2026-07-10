<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'related_type', 'related_id', 'field_name',
        'audio_file_path', 'audio_duration_seconds', 'audio_format',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
