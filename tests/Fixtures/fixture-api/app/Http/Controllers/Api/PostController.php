<?php

namespace App\Http\Controllers\Api;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PostController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(): JsonResponse
    {
        return response()->json(Post::with('author')->paginate(20));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Post::with('author')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body'  => 'required|string',
        ]);

        $post = Post::create(array_merge($validated, ['user_id' => $request->user()->id]));

        return response()->json($post, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);
        $post->update($request->validate(['title' => 'string|max:255', 'body' => 'string']));

        return response()->json($post);
    }

    public function destroy(int $id): JsonResponse
    {
        Post::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
