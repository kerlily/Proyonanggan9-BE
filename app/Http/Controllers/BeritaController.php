<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Berita;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class BeritaController extends Controller
{
    // list published berita (public)
    public function index()
    {
        $beritas = Berita::where('is_published', true)->orderByDesc('published_at')->get();
        return response()->json($beritas);
    }

    // show single berita
    public function show($id)
    {
        $berita = Berita::findOrFail($id);
        return response()->json($berita);
    }

    // create berita (guru/admin)
    public function store(Request $request)
    {
        $user = auth()->guard('api')->user();
    if (! in_array($user->role, ['admin','guru'])) {
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

        return response()->json(['message' => 'Berita created', 'data' => $berita], 201);
    }

    // update berita (only author or admin)
    public function update(Request $request, $id)
    {
        $user = auth()->guard('api')->user();
    $berita = Berita::findOrFail($id);

    if ($user->id !== $berita->created_by && $user->role !== 'admin') {
        return response()->json(['message' => 'Forbidden'], 403);
    }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ]);

        if ($request->hasFile('image')) {
            if ($berita->image && Storage::disk('public')->exists($berita->image)) {
                Storage::disk('public')->delete($berita->image);
            }
            $berita->image = $request->file('image')->store('beritas', 'public');
        }

        $berita->title = $request->input('title', $berita->title);
        $berita->description = $request->input('description', $berita->description);
        if (!is_null($request->input('is_published'))) {
            $berita->is_published = (bool)$request->input('is_published');
            if ($berita->is_published && !$berita->published_at) {
                $berita->published_at = now();
            }
        }
        if ($request->filled('published_at')) {
            $berita->published_at = $request->input('published_at');
        }
        $berita->save();

        return response()->json(['message' => 'Berita updated', 'data' => $berita]);
    }

    // delete berita (only author or admin)
    public function destroy($id)
    {
        $user = auth()->guard('api')->user();
    $berita = Berita::findOrFail($id);

    if ($user->id !== $berita->created_by && $user->role !== 'admin') {
        return response()->json(['message' => 'Forbidden'], 403);
    }

        if ($berita->image && Storage::disk('public')->exists($berita->image)) {
            Storage::disk('public')->delete($berita->image);
        }

        $berita->delete();

        return response()->json(['message' => 'Berita deleted']);
    }
}
