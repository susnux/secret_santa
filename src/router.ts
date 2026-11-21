/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteLocationNormalized } from 'vue-router'

import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'
import EmptyView from './views/EmptyView.vue'

const ListView = () => import('./views/ListView.vue')

export const routes = [
	{ path: '/', name: 'root', component: EmptyView },
	{ path: '/not-found', name: 'not-found', component: EmptyView },
	{
		path: '/:listId(\\d+)',
		name: 'list',
		component: ListView,
		props: (route: RouteLocationNormalized) => ({ listId: String(route.params.listId) }),
	},
]

export const router = createRouter({
	history: createWebHistory(generateUrl('/apps/secret_santa')),
	routes,
})
