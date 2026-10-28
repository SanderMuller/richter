export function savePost(title: string, body: string) {
    return axios.post('/posts', { title, body });
}

export function updatePost(id: number) {
    return axios.put(route('posts.update', id), { id });
}
