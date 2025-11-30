<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    // Public: list all galleries (id + image_url)
    public function index()
    {
        $galleries = Gallery::orderByDesc('created_at')->get()->map(function($g){
            return [
                'id' => $g->id,
                'image_url' => $g->image_url,
            ];
        });

        return response()->json(['galleries' => $galleries]);
    }

    // Public show single
    public function show($id)
    {
        $g = Gallery::findOrFail($id);
        return response()->json([
            'id' => $g->id,
            'image_url' => $g->image_url,
        ]);
    }

    // Store (only admin or guru)
    public function store(Request $request)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            $user = auth()->user();
        }

        if (!$user || !in_array($user->role, ['admin','guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'image' => 'required|image|max:5120', // max 5MB
        ]);

        $path = $request->file('image')->store('galleries', 'public');

        $gallery = Gallery::create([
            'image' => $path,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Gallery image uploaded',
            'data' => [
                'id' => $gallery->id,
                'image_url' => $gallery->image_url,
            ]
        ], 201);
    }

    // Update image (semua guru atau admin bisa update)
    public function update(Request $request, $id)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            $user = auth()->user();
        }

        // FIX: Izinkan semua admin ATAU guru update gallery
        $isAdmin = $user && $user->role === 'admin';
        $isGuru = $user && $user->role === 'guru';

        if (!$isAdmin && !$isGuru) {
            return response()->json([
                'message' => 'Forbidden - Only admin or guru can update gallery'
            ], 403);
        }

        $gallery = Gallery::findOrFail($id);

        $request->validate([
            'image' => 'required|image|max:5120',
        ]);

        // delete old file if exists
        if ($gallery->image && Storage::disk('public')->exists($gallery->image)) {
            Storage::disk('public')->delete($gallery->image);
        }

        $path = $request->file('image')->store('galleries', 'public');
        $gallery->image = $path;
        $gallery->save();

        return response()->json([
            'message' => 'Gallery updated',
            'data' => [
                'id' => $gallery->id,
                'image_url' => $gallery->image_url,
            ]
        ]);
    }

    // Delete (semua guru atau admin bisa delete)
    public function destroy($id)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            $user = auth()->user();
        }

        // FIX: Izinkan semua admin ATAU guru delete gallery
        $isAdmin = $user && $user->role === 'admin';
        $isGuru = $user && $user->role === 'guru';

        if (!$isAdmin && !$isGuru) {
            return response()->json([
                'message' => 'Forbidden - Only admin or guru can delete gallery'
            ], 403);
        }

        $gallery = Gallery::findOrFail($id);

        if ($gallery->image && Storage::disk('public')->exists($gallery->image)) {
            Storage::disk('public')->delete($gallery->image);
        }

        $gallery->delete();

        return response()->json(['message' => 'Gallery deleted']);
    }
}
