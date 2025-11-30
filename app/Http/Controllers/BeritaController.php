<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Berita;
use Illuminate\Support\Facades\Storage;

class BeritaController extends Controller
{
    // list published berita (public) -> mengembalikan { beritas: [...] }
    public function index()
    {
        // HANYA berita yang published = true
        $beritas = Berita::where('is_published', true)
            ->orderByDesc('published_at')
            ->get()
            ->map(function($b) {
                return [
                    'id' => $b->id,
                    'title' => $b->title,
                    'description' => $b->description,
                    'is_published' => (bool)$b->is_published,
                    'published_at' => $b->published_at,
                    'created_by' => $b->created_by,
                    'image_url' => $b->image_url,
                ];
            });

        return response()->json(['beritas' => $beritas]);
    }

    // show single berita -> mengembalikan { berita: {...} }
    public function show($id)
    {
        $b = Berita::find($id);

        if (!$b) {
            return response()->json([
                'message' => 'Berita tidak ditemukan'
            ], 404);
        }

        // TAMBAHAN: Cek apakah berita published atau user adalah admin/guru
        $user = auth()->guard('api')->user();

        // Jika berita tidak published dan user bukan admin/guru, return 403
        if (!$b->is_published && (!$user || !in_array($user->role, ['admin', 'guru']))) {
            return response()->json([
                'message' => 'Berita tidak tersedia'
            ], 403);
        }

        $data = [
            'id' => $b->id,
            'title' => $b->title,
            'description' => $b->description,
            'is_published' => (bool)$b->is_published,
            'published_at' => $b->published_at,
            'created_by' => $b->created_by,
            'image_url' => $b->image_url,
        ];

        return response()->json(['berita' => $data]);
    }

    // Get semua berita (published + draft) - hanya untuk guru/admin
    public function all()
    {
        $user = auth()->guard('api')->user();

        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // SEMUA berita (published + unpublished)
        $beritas = Berita::orderByDesc('created_at')
            ->get()
            ->map(function($b) {
                return [
                    'id' => $b->id,
                    'title' => $b->title,
                    'description' => $b->description,
                    'is_published' => (bool)$b->is_published,
                    'published_at' => $b->published_at,
                    'created_by' => $b->created_by,
                    'image_url' => $b->image_url,
                    'created_at' => $b->created_at,
                    'updated_at' => $b->updated_at,
                ];
            });

        return response()->json(['beritas' => $beritas]);
    }

    // create berita (guru/admin)
    public function store(Request $request)
    {
        $user = auth()->guard('api')->user();

        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ]);

        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('beritas', 'public');
        }

        $berita = Berita::create([
            'title' => $request->title,
            'description' => $request->description,
            'image' => $path,
            'created_by' => $user->id,
            'is_published' => $request->input('is_published', true),
            'published_at' => $request->input('published_at', $request->input('is_published') ? now() : null),
        ]);

        return response()->json([
            'message' => 'Berita created',
            'data' => [
                'id' => $berita->id,
                'title' => $berita->title,
                'description' => $berita->description,
                'is_published' => (bool)$berita->is_published,
                'published_at' => $berita->published_at,
                'created_by' => $berita->created_by,
                'image_url' => $berita->image_url,
            ]
        ], 201);
    }

    // update berita (only author or admin)
    public function update(Request $request, $id)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            $user = auth()->user();
        }

        $berita = Berita::find($id);
        if (!$berita) {
            return response()->json([
                'message' => 'Berita tidak ditemukan'
            ], 404);
        }

        $isAdmin = $user->role === 'admin';
        $isGuru = $user->role === 'guru';

        if (!$isAdmin && !$isGuru) {
            return response()->json([
                'message' => 'Forbidden - Only admin or guru can update berita'
            ], 403);
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'is_published' => 'nullable',
            'published_at' => 'nullable|date',
        ]);

        if ($request->hasFile('image')) {
            if ($berita->image && Storage::disk('public')->exists($berita->image)) {
                Storage::disk('public')->delete($berita->image);
            }
            $berita->image = $request->file('image')->store('beritas', 'public');
        }

        if ($request->filled('title')) {
            $berita->title = $request->input('title');
        }

        if ($request->has('description')) {
            $berita->description = $request->input('description');
        }

        if ($request->has('is_published')) {
            $isPublishedValue = $request->input('is_published');

            if (is_string($isPublishedValue)) {
                $berita->is_published = in_array($isPublishedValue, ['1', 'true', 'yes', true], true);
            } else {
                $berita->is_published = (bool)$isPublishedValue;
            }

            if ($berita->is_published && !$berita->published_at) {
                $berita->published_at = now();
            }
        }

        if ($request->filled('published_at')) {
            $berita->published_at = $request->input('published_at');
        }

        $berita->save();

        return response()->json([
            'message' => 'Berita updated',
            'data' => [
                'id' => $berita->id,
                'title' => $berita->title,
                'description' => $berita->description,
                'is_published' => (bool)$berita->is_published,
                'published_at' => $berita->published_at,
                'created_by' => $berita->created_by,
                'image_url' => $berita->image_url,
            ]
        ]);
    }

    // delete berita (only author or admin)
    public function destroy($id)
    {
        $user = auth()->guard('api')->user();

        $berita = Berita::find($id);
        if (!$berita) {
            return response()->json([
                'message' => 'Berita tidak ditemukan'
            ], 404);
        }

        if (!$user || ($user->id !== $berita->created_by && $user->role !== 'admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($berita->image && Storage::disk('public')->exists($berita->image)) {
            Storage::disk('public')->delete($berita->image);
        }

        $berita->delete();

        return response()->json(['message' => 'Berita deleted']);
    }
}
