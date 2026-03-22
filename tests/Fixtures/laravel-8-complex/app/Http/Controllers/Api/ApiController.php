<?php

namespace App\Http\Controllers\Api;

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ApiController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $posts = Post::with('author')
            ->latest()
            ->paginate(15);

        return response()->json($posts);
    }

    public function show(int $id): JsonResponse
    {
        $post = Post::with('author')->findOrFail($id);

        return response()->json($post);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body'  => 'required|string',
        ]);

        $post = Post::create(array_merge($validated, [
            'user_id' => $request->user()->id,
        ]));

        return response()->json($post, 201);
    }
}
