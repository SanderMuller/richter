import { show } from '@/actions/App/Http/Controllers/Post/ReviewController';

test('loads the review for a post', async () => {
    const response = await fetch(show(1).url);

    expect(response.ok).toBe(true);
});
