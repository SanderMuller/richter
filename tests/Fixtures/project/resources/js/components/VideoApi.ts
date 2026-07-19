import { show } from '@/actions/App/Http/Controllers/Video/QuestionController';

export function loadQuestion(videoId: number) {
    return fetch(show(videoId).url);
}

export function editVideo(videoId: number) {
    return fetch(route('videos.edit', videoId));
}

export function searchDashboard() {
    return fetch('/dashboard/search');
}
