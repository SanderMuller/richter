@extends('layouts.app')

@include('posts.partials.header')

@can(App\Policies\PostPolicy::UPDATE, $post)
    <a href="{{ route('posts.edit', $post) }}">Edit</a>
@endcan
