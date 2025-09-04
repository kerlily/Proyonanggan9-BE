<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Jadwal;
use App\Models\Kelas;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class JadwalController extends Controller
{
    // list jadwal for a kelas (public)
    public function index($kelas_id)
    {
        $kelas = Kelas::findOrFail($kelas_id);
        $jadwals = Jadwal::where('kelas_id', $kelas_id)->orderByDesc('created_at')->get();
        return response()->json(['kelas' => $kelas->nama, 'data' => $jadwals]);
    }

    // show single jadwal
    public function show($kelas_id, $id)
    {
        $jadwal = Jadwal::where('kelas_id', $kelas_id)->findOrFail($id);
        return response()->json($jadwal);
    }

    // create jadwal (ONLY wali.kelas middleware should allow)
    public function store(Request $request, $kelas_id)
    {
        $request->validate([
            'image' => 'required|image|max:5120', // max 5MB
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'semester_id' => 'nullable|integer|exists:semester,id',
        ]);

        // ensure kelas exists
        Kelas::findOrFail($kelas_id);

        // store file
        $path = $request->file('image')->store('jadwals', 'public');

        $jadwal = Jadwal::create([
            'kelas_id' => $kelas_id,
            'guru_id' => auth()->guard('api')->user()->guru->id ?? null,
            'semester_id' => $request->input('semester_id'),
            'image' => $path,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Jadwal created', 'data' => $jadwal], 201);
    }

    // update jadwal (only wali)
    public function update(Request $request, $kelas_id, $id)
    {
        $jadwal = Jadwal::where('kelas_id', $kelas_id)->findOrFail($id);

        $request->validate([
            'image' => 'nullable|image|max:5120',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'semester_id' => 'nullable|integer|exists:semester,id',
            'is_active' => 'nullable|boolean',
        ]);

        // if new image uploaded, delete old
        if ($request->hasFile('image')) {
            if ($jadwal->image && Storage::disk('public')->exists($jadwal->image)) {
                Storage::disk('public')->delete($jadwal->image);
            }
            $path = $request->file('image')->store('jadwals', 'public');
            $jadwal->image = $path;
        }

        $jadwal->title = $request->input('title', $jadwal->title);
        $jadwal->description = $request->input('description', $jadwal->description);
        $jadwal->semester_id = $request->input('semester_id', $jadwal->semester_id);
        if (!is_null($request->input('is_active'))) {
            $jadwal->is_active = (bool)$request->input('is_active');
        }
        $jadwal->save();

        return response()->json(['message' => 'Jadwal updated', 'data' => $jadwal]);
    }

    // delete jadwal (only wali)
    public function destroy($kelas_id, $id)
    {
        $jadwal = Jadwal::where('kelas_id', $kelas_id)->findOrFail($id);

        // delete file
        if ($jadwal->image && Storage::disk('public')->exists($jadwal->image)) {
            Storage::disk('public')->delete($jadwal->image);
        }

        $jadwal->delete();

        return response()->json(['message' => 'Jadwal deleted']);
    }
}
