export function savePost(title: string, body: string) {
    return axios.post('/posts', { title, body });
}
