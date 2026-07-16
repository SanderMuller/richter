@extends('layouts.app')

@include('videos.partials.header')

@can(App\Policies\VideoPolicy::UPDATE, $video)
    <a href="{{ route('videos.edit', $video) }}">Edit</a>
@endcan
