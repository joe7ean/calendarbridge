import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = generateUrl('/apps/calendarbridge/api')

export const feedsApi = {
	list: () => axios.get(`${base}/feeds`),
	get: (id) => axios.get(`${base}/feeds/${id}`),
	create: (data) => axios.post(`${base}/feeds`, data),
	update: (id, data) => axios.put(`${base}/feeds/${id}`, data),
	delete: (id) => axios.delete(`${base}/feeds/${id}`),
	preview: (data) => axios.post(`${base}/feeds/preview`, data),
	manualSync: (id) => axios.post(`${base}/feeds/${id}/sync`),
}

export const settingsApi = {
	get: () => axios.get(`${base}/settings`),
	save: (data) => axios.put(`${base}/settings`, data),
}

export const groupsApi = {
	list: (search = '') => axios.get(`${base}/groups`, { params: { search } }),
}

export const usersApi = {
	list: (search = '') => axios.get(`${base}/users`, { params: { search } }),
}
