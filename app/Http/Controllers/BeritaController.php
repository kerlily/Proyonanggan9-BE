<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Berita;
use Illuminate\Support\Facades\Storage;

class BeritaController extends Controller
{
    // ─── list published berita (public) ─────────────────────────────────────────
    public function index()
    {
        $beritas = Berita::where('is_published', true)
            ->orderByDesc('published_at')
            ->get()
            ->map(fn($b) => $this->formatBerita($b));

        return response()->json(['beritas' => $beritas]);
    }

    // ─── list pengumuman (public) ────────────────────────────────────────────────
    public function indexPengumuman()
    {
        $pengumuman = Berita::where('is_published', true)
            ->where('type', 'pengumuman')
            ->orderByDesc('published_at')
            ->get()
            ->map(fn($b) => $this->formatBerita($b));

        return response()->json(['pengumuman' => $pengumuman]);
    }

    // ─── show single ─────────────────────────────────────────────────────────────
    public function show($id)
    {
        $b = Berita::find($id);
        if (!$b) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }

        $user = auth()->guard('api')->user();
        if (!$b->is_published && (!$user || !in_array($user->role, ['admin', 'guru']))) {
            return response()->json(['message' => 'Berita tidak tersedia'], 403);
        }

        return response()->json(['berita' => $this->formatBerita($b)]);
    }

    // ─── all (published + draft) untuk guru/admin ────────────────────────────────
    public function all()
    {
        $user = auth()->guard('api')->user();
        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $beritas = Berita::orderByDesc('created_at')
            ->get()
            ->map(fn($b) => $this->formatBerita($b, true));

        return response()->json(['beritas' => $beritas]);
    }

    // ─── store ───────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'image'        => 'nullable|image|max:5120',
            'type'         => 'nullable|in:berita,pengumuman',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            // mimes mencakup pdf, zip, rar (application/octet-stream), doc, docx
            'attachment'   => 'nullable|file|max:20480|mimes:pdf,zip,rar,doc,docx',
        ]);

        $imagePath      = null;
        $attachmentPath = null;
        $attachmentName = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('beritas', 'public');
        }

        if ($request->hasFile('attachment')) {
            $file           = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = $file->store('beritas/attachments', 'public');
        }

        $berita = Berita::create([
            'title'           => $request->title,
            'description'     => $request->description,
            'type'            => $request->input('type', 'berita'),
            'image'           => $imagePath,
            'attachment'      => $attachmentPath,
            'attachment_name' => $attachmentName,
            'created_by'      => $user->id,
            'is_published'    => $request->input('is_published', true),
            'published_at'    => $request->input('published_at',
                                    $request->input('is_published') ? now() : null),
        ]);

        return response()->json([
            'message' => 'Berita created',
            'data'    => $this->formatBerita($berita),
        ], 201);
    }

    // ─── update ──────────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $user = auth()->guard('api')->user() ?? auth()->user();

        $berita = Berita::find($id);
        if (!$berita) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }
        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'title'             => 'nullable|string|max:255',
            'description'       => 'nullable|string',
            'image'             => 'nullable|image|max:5120',
            'type'              => 'nullable|in:berita,pengumuman',
            'is_published'      => 'nullable',
            'published_at'      => 'nullable|date',
            'attachment'        => 'nullable|file|max:20480|mimes:pdf,zip,rar,doc,docx',
            'remove_attachment' => 'nullable',
        ]);

        // Image
        if ($request->hasFile('image')) {
            if ($berita->image && Storage::disk('public')->exists($berita->image)) {
                Storage::disk('public')->delete($berita->image);
            }
            $berita->image = $request->file('image')->store('beritas', 'public');
        }

        // Hapus attachment lama jika diminta
        $shouldRemove = filter_var($request->input('remove_attachment'), FILTER_VALIDATE_BOOLEAN);
        if ($shouldRemove && $berita->attachment) {
            if (Storage::disk('public')->exists($berita->attachment)) {
                Storage::disk('public')->delete($berita->attachment);
            }
            $berita->attachment      = null;
            $berita->attachment_name = null;
        }

        // Upload attachment baru
        if ($request->hasFile('attachment')) {
            if ($berita->attachment && Storage::disk('public')->exists($berita->attachment)) {
                Storage::disk('public')->delete($berita->attachment);
            }
            $file                    = $request->file('attachment');
            $berita->attachment_name = $file->getClientOriginalName();
            $berita->attachment      = $file->store('beritas/attachments', 'public');
        }

        if ($request->filled('title'))    $berita->title       = $request->title;
        if ($request->has('description')) $berita->description = $request->description;
        if ($request->has('type'))        $berita->type        = $request->type;

        if ($request->has('is_published')) {
            $val = $request->input('is_published');
            $berita->is_published = is_string($val)
                ? in_array($val, ['1', 'true', 'yes', true], true)
                : (bool) $val;

            if ($berita->is_published && !$berita->published_at) {
                $berita->published_at = now();
            }
        }

        if ($request->filled('published_at')) {
            $berita->published_at = $request->published_at;
        }

        $berita->save();

        return response()->json([
            'message' => 'Berita updated',
            'data'    => $this->formatBerita($berita),
        ]);
    }

    // ─── destroy ─────────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $user = auth()->guard('api')->user();

        $berita = Berita::find($id);
        if (!$berita) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }
        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($berita->image && Storage::disk('public')->exists($berita->image)) {
            Storage::disk('public')->delete($berita->image);
        }
        if ($berita->attachment && Storage::disk('public')->exists($berita->attachment)) {
            Storage::disk('public')->delete($berita->attachment);
        }

        $berita->delete();

        return response()->json(['message' => 'Berita deleted']);
    }

    // ─── download attachment ──────────────────────────────────────────────────────
    // Route: GET /api/beritas/{id}/download
    public function downloadAttachment($id)
    {
        $berita = Berita::find($id);

        if (!$berita) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }
        if (!$berita->attachment) {
            return response()->json(['message' => 'Tidak ada file attachment'], 404);
        }

        // Draft hanya bisa didownload guru/admin
        if (!$berita->is_published) {
            $user = auth()->guard('api')->user();
            if (!$user || !in_array($user->role, ['admin', 'guru'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (!Storage::disk('public')->exists($berita->attachment)) {
            return response()->json(['message' => 'File tidak ditemukan di server'], 404);
        }

        $fileName = $berita->attachment_name ?? basename($berita->attachment);

        return Storage::disk('public')->download($berita->attachment, $fileName);
    }

    // ─── helper ──────────────────────────────────────────────────────────────────
    private function formatBerita(Berita $b, bool $withTimestamps = false): array
    {
        $data = [
            'id'              => $b->id,
            'title'           => $b->title,
            'description'     => $b->description,
            'type'            => $b->type,
            'is_published'    => (bool) $b->is_published,
            'published_at'    => $b->published_at,
            'created_by'      => $b->created_by,
            'image_url'       => $b->image_url,
            // Attachment — URL mengarah ke route download (bukan storage langsung)
            // supaya bisa dikontrol auth + header Content-Disposition
            'has_attachment'  => (bool) $b->attachment,
            'attachment_name' => $b->attachment_name,
            'attachment_url'  => $b->attachment
                                    ? route('beritas.download', ['id' => $b->id])
                                    : null,
        ];

        if ($withTimestamps) {
            $data['created_at'] = $b->created_at;
            $data['updated_at'] = $b->updated_at;
        }

        return $data;
    }
}
