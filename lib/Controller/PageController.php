<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;

/**
 * @psalm-api Loaded by the router
 */
final class PageController extends Controller {

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		Util::addScript($this->appName, 'secret_santa-main');
		Util::addStyle($this->appName, 'secret_santa-main');

		return new TemplateResponse($this->appName, 'main');
	}

	/**
	 * Page shown by the frontend when a list does not exist
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/not-found')]
	public function notFound(): TemplateResponse {
		return $this->index();
	}

	/**
	 * Page with the archived lists
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archived')]
	public function archived(): TemplateResponse {
		return $this->index();
	}

	/**
	 * Page of a list, the frontend loads the list and shows "not found" if it is not accessible
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/{listId}', requirements: ['listId' => '\d+'])]
	public function showList(): TemplateResponse {
		return $this->index();
	}
}
