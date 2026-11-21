/*!
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: MIT
 */

import { configureNextcloud, runExec, runOcc, startNextcloud, stopNextcloud, waitOnNextcloud } from '@nextcloud/e2e-test-server/docker'
import { execSync } from 'node:child_process'
import { readFileSync } from 'node:fs'

/**
 * Get the server branch matching the maximum Nextcloud version the app supports,
 * falling back to master if there is no stable branch for it (yet).
 */
function getServerBranch() {
	const appinfo = readFileSync('appinfo/info.xml').toString()
	const maxVersion = appinfo.match(/<nextcloud\s+min-version="\d+"\s+max-version="(\d+)"\s*\/>/)?.[1]
	if (!maxVersion) {
		return 'master'
	}

	const refs = execSync('git ls-remote --heads https://github.com/nextcloud/server.git', { encoding: 'utf-8' })
	return refs.includes(`refs/heads/stable${maxVersion}\n`) ? `stable${maxVersion}` : 'master'
}

async function start() {
	const port = Number.parseInt(process.env.NEXTCLOUD_PORT ?? '8089', 10)
	const ip = await startNextcloud(process.env.BRANCH ?? getServerBranch(), true, {
		exposePort: port,
	})
	await waitOnNextcloud(ip)

	// createRandomUser() generates passwords that the password policy rejects
	await runOcc(['app:disable', 'password_policy'])
	// otherwise app config changes made with occ only reach the web server after the cache TTL
	await runOcc(['config:system:set', 'cache_app_config', '--value=false', '--type=boolean'])
	await runExec(['php', '-r', '$db = new SQLite3("data/owncloud.db");$db->busyTimeout(5000);$db->exec("PRAGMA journal_mode = wal;");'])

	// emails are accepted without being delivered
	await runOcc(['config:system:set', 'mail_smtpmode', '--value=null'])

	// prints the line Playwright waits for, so it has to run last
	await configureNextcloud(['secret_santa'])
}

async function stop() {
	process.stderr.write('Stopping Nextcloud server…\n')
	await stopNextcloud({ saveLogTo: 'nextcloud.log' })
	process.exit(0)
}

process.on('SIGTERM', stop)
process.on('SIGINT', stop)

await start()

// Idle to wait for shutdown
while (true) {
	await new Promise((resolve) => setTimeout(resolve, 5000))
}
