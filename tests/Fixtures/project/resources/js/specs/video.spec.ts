import { show } from '@/actions/App/Http/Controllers/Video/QuestionController';

test('loads the question for a video', async () => {
    const response = await fetch(show(1).url);

    expect(response.ok).toBe(true);
});
