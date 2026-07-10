<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BehaviorAnnotation;
use App\Models\ClassPlan;
use App\Models\ParentCitation;
use App\Models\StudentObservation;
use App\Models\VoiceNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VoiceNoteController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'relatedType' => ['required', 'in:class_plan,behavior_annotation,observation,citation'],
            'relatedId' => ['required', 'integer'],
        ]);

        $this->authorizeAccess($request->relatedType, $request->relatedId, $request->user());

        $notes = VoiceNote::where('related_type', $request->relatedType)
            ->where('related_id', $request->relatedId)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $notes]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'related_type' => ['required', 'in:class_plan,behavior_annotation,observation,citation'],
            'related_id' => ['required', 'integer'],
            'field_name' => ['nullable', 'string', 'max:255'],
            'audio' => ['required', 'file', 'mimetypes:audio/webm,video/webm,audio/mpeg,audio/ogg,audio/wav,audio/x-wav', 'max:10240'],
            'audio_duration_seconds' => ['nullable', 'integer'],
        ]);

        $this->authorizeAccess($validated['related_type'], $validated['related_id'], $request->user());
        $institutionId = $this->resolveInstitutionId($validated['related_type'], $validated['related_id']);

        $file = $request->file('audio');
        $extension = $file->getClientOriginalExtension() ?: 'webm';
        $path = "voice-notes/{$institutionId}/{$request->user()->id}/".now()->year;
        $filename = Str::uuid().'.'.$extension;
        $file->storeAs($path, $filename, 'local');

        $voiceNote = VoiceNote::create([
            'user_id' => $request->user()->id,
            'related_type' => $validated['related_type'],
            'related_id' => $validated['related_id'],
            'field_name' => $validated['field_name'] ?? null,
            'audio_file_path' => "{$path}/{$filename}",
            'audio_duration_seconds' => $validated['audio_duration_seconds'] ?? 0,
            'audio_format' => in_array($extension, ['webm', 'mp3', 'ogg']) ? $extension : 'webm',
        ]);

        return response()->json(['data' => $voiceNote], 201);
    }

    public function show(Request $request, VoiceNote $voiceNote)
    {
        $this->authorizeAccess($voiceNote->related_type, $voiceNote->related_id, $request->user());

        return Storage::disk('local')->response($voiceNote->audio_file_path);
    }

    public function destroy(Request $request, VoiceNote $voiceNote)
    {
        abort_unless($voiceNote->user_id === $request->user()->id, 403);

        Storage::disk('local')->delete($voiceNote->audio_file_path);
        $voiceNote->delete();

        return response()->json(['message' => 'Nota de voz eliminada.']);
    }

    private function authorizeAccess(string $relatedType, int $relatedId, $user): void
    {
        match ($relatedType) {
            'class_plan' => $this->authorize('view', ClassPlan::findOrFail($relatedId)->groupSubject),
            'behavior_annotation' => abort_unless(BehaviorAnnotation::findOrFail($relatedId)->student->canBeAccessedBy($user), 403),
            'observation' => abort_unless(StudentObservation::findOrFail($relatedId)->student->canBeAccessedBy($user), 403),
            'citation' => abort_unless(ParentCitation::findOrFail($relatedId)->student->canBeAccessedBy($user), 403),
        };
    }

    private function resolveInstitutionId(string $relatedType, int $relatedId): int
    {
        return match ($relatedType) {
            'class_plan' => ClassPlan::findOrFail($relatedId)->groupSubject->institution_id,
            'behavior_annotation' => BehaviorAnnotation::findOrFail($relatedId)->student->institution_id,
            'observation' => StudentObservation::findOrFail($relatedId)->student->institution_id,
            'citation' => ParentCitation::findOrFail($relatedId)->student->institution_id,
        };
    }
}
