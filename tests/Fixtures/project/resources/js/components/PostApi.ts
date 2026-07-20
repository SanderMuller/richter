import { show } from '@/actions/App/Http/Controllers/Post/ReviewController';

export function loadReview(postId: number) {
    return fetch(show(postId).url);
}

export function editPost(postId: number) {
    return fetch(route('posts.edit', postId));
}

export function searchDashboard() {
    return fetch('/dashboard/search');
}
