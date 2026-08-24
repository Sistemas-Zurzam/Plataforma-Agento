import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://164.92.77.253/api',
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('access_token');
      window.dispatchEvent(new CustomEvent('auth:unauthorized', {
        detail: { message: error.response?.data?.message ?? 'La sesión expiró' },
      }));
    }
    return Promise.reject(error);
  },
);

export default api;
