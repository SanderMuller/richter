export async function loadReview(postId: number) {
    const review = await fetch(`/posts/${postId}/reviews`).then((response) => response.json());

    return review.summary;
}
