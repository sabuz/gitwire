/* global GFW, jQuery */
(function ($) {
	'use strict';

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	function ajax(action, data, btn) {
		if (btn) {
			$(btn).prop('disabled', true).data('original-text', $(btn).text());
		}
		return $.post(GHWP.ajax_url, Object.assign({ action: action, nonce: GHWP.nonce }, data))
			.always(function () {
				if (btn) {
					$(btn).prop('disabled', false).text($(btn).data('original-text'));
				}
			});
	}

	function spinner() {
		return '<span class="gwp-spinner"></span>';
	}

	function msg(el, text, type) {
		$(el).removeClass('success error').addClass(type).text(text);
	}

	function humanDate(iso) {
		if (!iso) return '—';
		return new Date(iso).toLocaleDateString();
	}

	// -----------------------------------------------------------------------
	// Tab navigation
	// -----------------------------------------------------------------------

	$(document).on('click', '.gwp-tab', function () {
		var tab = $(this).data('tab');
		$('.gwp-tab').removeClass('active');
		$(this).addClass('active');
		$('.gwp-panel').removeClass('active');
		$('#gwp-tab-' + tab).addClass('active');

		// Auto-load repos when Browse is opened for the first time
		if (tab === 'browse' && $('#gwp-repo-list .gwp-repo-card').length === 0) {
			$('#gwp-load-repos').trigger('click');
		}
	});

	// -----------------------------------------------------------------------
	// Settings: save
	// -----------------------------------------------------------------------

	$('#gwp-save-settings').on('click', function () {
		if ( ! $('#gwp-username').val().trim() ) {
			msg('#gwp-settings-msg', 'GitHub Username is required.', 'error');
			$('#gwp-username').focus();
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true);
		msg('#gwp-settings-msg', '', '');

		$.post(GHWP.ajax_url, {
			action:        'gwp_save_settings',
			nonce:         GHWP.nonce,
			token:         $('#gwp-token').val(),
			username:      $('#gwp-username').val(),
			smart_install: $('#gwp-smart-install').is(':checked') ? 1 : 0,
		}).done(function (res) {
			if (res.success) {
				GHWP.smart_install = res.data.smart_install;
				msg('#gwp-settings-msg', res.data.message, 'success');
				runTestConnection();
			} else {
				msg('#gwp-settings-msg', res.data, 'error');
			}
		}).fail(function () {
			msg('#gwp-settings-msg', GHWP.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// -----------------------------------------------------------------------
	// Settings: test connection
	// -----------------------------------------------------------------------

	$('#gwp-test-connection').on('click', runTestConnection);

	function runTestConnection() {
		var $btn = $('#gwp-test-connection');
		$btn.prop('disabled', true);
		renderStatusCard(null); // loading state

		$.post(GHWP.ajax_url, {
			action: 'gwp_test_connection',
			nonce:  GHWP.nonce,
		}).done(function (res) {
			if (res.success) {
				renderStatusCard(res.data);
				if (!$('#gwp-settings-msg').text()) {
					msg('#gwp-settings-msg', 'Connected!', 'success');
				}
			} else {
				renderStatusCard({ error: res.data });
				msg('#gwp-settings-msg', 'Connection failed: ' + res.data, 'error');
			}
		}).fail(function () {
			renderStatusCard({ error: GHWP.i18n.error });
			msg('#gwp-settings-msg', GHWP.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	function renderStatusCard(d) {
		var $card = $('#gwp-conn-status-card');

		if (d === null) {
			$card.html(
				'<div class="gwp-conn-empty">'
				+ '<span class="gwp-spinner" style="display:block;margin:0 auto 8px;"></span>'
				+ '<p>Checking connection…</p>'
				+ '</div>'
			);
			return;
		}

		if (d.error) {
			$card.html(
				'<div class="gwp-conn-info" style="text-align:center">'
				+ '<div class="gwp-conn-badge gwp-conn-unauthenticated" style="display:inline-flex">'
				+ '<span class="dashicons dashicons-warning"></span> Connection failed'
				+ '</div>'
				+ '<p style="font-size:12px;color:#57606a;margin-top:8px">' + escHtml(d.error) + '</p>'
				+ '</div>'
			);
			return;
		}

		var html = '';

		if (d.login) {
			html += '<img src="' + escAttr(d.avatar_url) + '" alt="" class="gwp-conn-avatar" />';
			html += '<div class="gwp-conn-info">';
			html += '<div class="gwp-conn-name">' + escHtml(d.name || d.login)
				+ '<span class="gwp-conn-login">@' + escHtml(d.login) + '</span></div>';
			html += '<div class="gwp-conn-badge gwp-conn-authenticated">'
				+ '<span class="dashicons dashicons-yes-alt"></span> Authenticated</div>';
			html += '</div>';
		} else {
			html += '<div class="gwp-conn-info">';
			html += '<div class="gwp-conn-badge gwp-conn-unauthenticated">'
				+ '<span class="dashicons dashicons-warning"></span> No token — public repos only</div>';
			html += '</div>';
		}

		if (d.rate_limit !== undefined) {
			var limit     = d.rate_limit;
			var remaining = d.rate_remaining;
			var pct       = limit > 0 ? Math.round((remaining / limit) * 100) : 0;
			var barClass  = pct > 50 ? 'good' : (pct > 20 ? 'warn' : 'danger');
			var note      = limit === 60
				? 'Unauthenticated limit — shared by your server\'s IP. Add a token for 5,000/hour.'
				: 'Resets in about an hour.';

			html += '<hr class="gwp-conn-divider" />';
			html += '<div class="gwp-rate-info">';
			html += '<div class="gwp-rate-label"><span>API requests this hour</span>'
				+ '<strong>' + remaining.toLocaleString() + ' / ' + limit.toLocaleString() + '</strong></div>';
			html += '<div class="gwp-rate-bar-track">'
				+ '<div class="gwp-rate-bar gwp-rate-bar--' + barClass + '" style="width:' + pct + '%"></div></div>';
			html += '<p class="gwp-rate-note">' + escHtml(note) + '</p>';
			html += '</div>';
		}

		html += '<p class="gwp-conn-checked">Just checked</p>';

		$card.html(html);
	}

	// -----------------------------------------------------------------------
	// Browse: load repos
	// -----------------------------------------------------------------------

	var currentPage = 1;

	function loadRepos(page, append) {
		var $list = $('#gwp-repo-list');
		var $btn  = $('#gwp-load-repos');

		if (!append) {
			$list.html('<p>' + spinner() + ' Loading repositories…</p>');
		}
		$btn.prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action: 'gwp_get_repos',
			nonce:  GHWP.nonce,
			page:   page,
		}).done(function (res) {
			if (!res.success) {
				$list.html('<p class="gwp-empty" style="color:#cf222e">' + res.data + '</p>');
				return;
			}

			if (!append) {
				$list.empty();
			}

			if (res.data.repos.length === 0 && !append) {
				$list.html('<p class="gwp-empty">No repositories found.</p>');
				return;
			}

			res.data.repos.forEach(function (repo) {
				$list.append(buildRepoCard(repo));
			});

			applyFilter();

			// Queue detection for any cards that don't yet know their type.
			$('#gwp-repo-list .gwp-card-type-detecting').each(function () {
				var $b = $(this);
				if (!$b.data('queued')) {
					$b.data('queued', true);
					enqueueDetect($b.data('owner'), $b.data('repo'), $b.data('branch'), $b);
				}
			});

			currentPage = res.data.page;

			if (res.data.has_more) {
				$('#gwp-load-more-wrap').show();
				$('#gwp-load-more').data('page', currentPage + 1);
			} else {
				$('#gwp-load-more-wrap').hide();
			}
		}).fail(function () {
			$list.html('<p class="gwp-empty" style="color:#cf222e">' + GHWP.i18n.error + '</p>');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	// -----------------------------------------------------------------------
	// Card type badge + lazy detection queue
	// -----------------------------------------------------------------------

	var _detectQueue  = [];
	var _detectActive = 0;
	var DETECT_CONCURRENCY = 3;

	function enqueueDetect(owner, repo, branch, $badge) {
		_detectQueue.push({ owner: owner, repo: repo, branch: branch, $badge: $badge });
		drainDetectQueue();
	}

	function drainDetectQueue() {
		while (_detectActive < DETECT_CONCURRENCY && _detectQueue.length > 0) {
			var item = _detectQueue.shift();
			_detectActive++;
			(function (o, r, b, $b) {
				$.post(GHWP.ajax_url, {
					action: 'gwp_detect_repo',
					nonce:  GHWP.nonce,
					owner:  o,
					repo:   r,
					branch: b,
				}).done(function (res) {
					applyTypeBadge($b, res.success ? res.data : { type: 'unknown' });
				}).fail(function () {
					applyTypeBadge($b, { type: 'unknown' });
				}).always(function () {
					_detectActive--;
					drainDetectQueue();
				});
			}(item.owner, item.repo, item.branch, item.$badge));
		}
	}

	function applyTypeBadge($badge, d) {
		var type    = d.type    || 'unknown';
		var subtype = d.subtype || null;
		var cls, text;

		if (type === 'plugin') {
			cls  = 'gwp-card-type-plugin';
			text = 'Plugin';
		} else if (type === 'theme' && subtype === 'block') {
			cls  = 'gwp-card-type-theme';
			text = 'Block Theme';
		} else if (type === 'theme') {
			cls  = 'gwp-card-type-theme';
			text = 'Theme';
		} else {
			cls  = 'gwp-card-type-unknown';
			text = 'Unknown';
		}

		$badge.attr('class', 'gwp-card-type-badge ' + cls).text(text);
	}

	function buildTypeBadgeHtml(type, subtype) {
		var cls, text;
		if (type === 'plugin') {
			cls = 'gwp-card-type-plugin'; text = 'Plugin';
		} else if (type === 'theme' && subtype === 'block') {
			cls = 'gwp-card-type-theme';  text = 'Block Theme';
		} else if (type === 'theme') {
			cls = 'gwp-card-type-theme';  text = 'Theme';
		} else {
			cls = 'gwp-card-type-unknown'; text = 'Unknown';
		}
		return '<span class="gwp-card-type-badge ' + cls + '">' + text + '</span>';
	}

	// -----------------------------------------------------------------------

	function buildRepoCard(repo) {
		var isInstalled = !!repo.installed;
		var cls = 'gwp-repo-card' + (isInstalled ? ' gwp-installed' : '');
		var owner = repo.full_name.split('/')[0];

		var visibilityBadge = repo.private
			? '<span class="gwp-private-badge">Private</span>'
			: '<span class="gwp-public-badge">Public</span>';

		// Type badge: known immediately for installed repos, queued for detection otherwise.
		var typeBadge;
		if (isInstalled) {
			typeBadge = buildTypeBadgeHtml(repo.installed.type, null);
		} else {
			typeBadge = '<span class="gwp-card-type-badge gwp-card-type-detecting"'
				+ ' data-owner="' + escAttr(owner) + '"'
				+ ' data-repo="'  + escAttr(repo.name) + '"'
				+ ' data-branch="' + escAttr(repo.default_branch) + '">'
				+ '<span class="gwp-spinner gwp-spinner-xs"></span>'
				+ '</span>';
		}

		var desc = repo.description
			? '<p class="gwp-repo-desc">' + escHtml(repo.description) + '</p>'
			: '';

		var stars = repo.stargazers_count > 0
			? '<span><span class="dashicons dashicons-star-filled"></span> ' + repo.stargazers_count + '</span>'
			: '';

		var updated = repo.updated_at
			? '<span>Updated: ' + humanDate(repo.updated_at) + '</span>'
			: '';

		var footer = '';
		if (isInstalled) {
			footer = '<span class="gwp-installed-tag">&#10003; Installed (' + escHtml(repo.installed.branch) + ')</span>';
		} else {
			footer = '<button class="button button-small gwp-install-btn button-primary" '
				+ 'data-owner="' + escAttr(owner) + '" '
				+ 'data-repo="'  + escAttr(repo.name) + '" '
				+ 'data-default-branch="' + escAttr(repo.default_branch) + '" '
				+ 'data-full-name="' + escAttr(repo.full_name) + '">'
				+ 'Install</button>';
		}

		return '<div class="' + cls + '" data-full-name="' + escAttr(repo.full_name) + '">'
			+ '<div class="gwp-repo-card-header">'
			+ '<div class="gwp-repo-name"><a href="' + escAttr(repo.html_url) + '" target="_blank" rel="noopener">' + escHtml(repo.full_name) + '</a></div>'
			+ '<div class="gwp-card-badges">' + visibilityBadge + typeBadge + '</div>'
			+ '</div>'
			+ desc
			+ '<div class="gwp-repo-meta">' + stars + updated + '</div>'
			+ '<div class="gwp-repo-card-footer">' + footer + '</div>'
			+ '</div>';
	}

	$('#gwp-load-repos').on('click', function () {
		loadRepos(1, false);
	});

	$('#gwp-load-more').on('click', function () {
		loadRepos($(this).data('page'), true);
	});

	// -----------------------------------------------------------------------
	// Browse: filter
	// -----------------------------------------------------------------------

	$('#gwp-search').on('input', applyFilter);

	function applyFilter() {
		var q = $('#gwp-search').val().toLowerCase();
		$('#gwp-repo-list .gwp-repo-card').each(function () {
			var name = $(this).data('full-name').toLowerCase();
			$(this).toggle(!q || name.indexOf(q) !== -1);
		});
	}

	// -----------------------------------------------------------------------
	// Install modal
	// -----------------------------------------------------------------------

	var _modalOwner, _modalRepo, _modalFullName, _detectedType;

	$(document).on('click', '.gwp-install-btn', function () {
		var $btn = $(this);
		_modalOwner    = $btn.data('owner');
		_modalRepo     = $btn.data('repo');
		_modalFullName = $btn.data('full-name');
		_detectedType  = null;
		var defaultBranch = $btn.data('default-branch') || 'main';

		// Open modal in loading/detecting state
		$('#gwp-modal-repo-name').text(_modalFullName);
		$('#gwp-modal-branch-select').html('<option value="' + escAttr(defaultBranch) + '" selected>' + escHtml(defaultBranch) + '</option>');
		$('#gwp-modal-msg').removeClass('success error').text('');
		$('#gwp-modal-install').prop('disabled', true).text('Install');
		$('#gwp-modal-type-row').hide();
		$('#gwp-modal-detection')
			.attr('class', 'gwp-detection-row gwp-detection-loading')
			.html('<span class="gwp-spinner"></span> ' + GHWP.i18n.detecting);

		$('#gwp-branch-modal').show();

		// Load branches in parallel with detection
		loadBranchesIntoSelect('#gwp-modal-branch-select', _modalOwner, _modalRepo);

		// Detect project type
		$.post(GHWP.ajax_url, {
			action: 'gwp_detect_repo',
			nonce:  GHWP.nonce,
			owner:  _modalOwner,
			repo:   _modalRepo,
			branch: defaultBranch,
		}).done(function (res) {
			if (!res.success) {
				renderDetectionBadge({ type: 'unknown', confidence: 'none' });
				return;
			}
			renderDetectionBadge(res.data);
		}).fail(function () {
			renderDetectionBadge({ type: 'unknown', confidence: 'none' });
		});
	});

	function renderDetectionBadge(d) {
		var type       = d.type       || 'unknown';
		var subtype    = d.subtype    || null;
		var confidence = d.confidence || 'none';
		var name       = d.name       || '';
		var isKnown    = type === 'plugin' || type === 'theme';
		var smartOn    = !!GHWP.smart_install;

		_detectedType = isKnown ? type : null;

		var badgeClass, badgeText, icon;

		if (type === 'plugin') {
			badgeClass = confidence === 'high' ? 'gwp-detect-plugin' : 'gwp-detect-plugin gwp-detect-medium';
			icon       = '🔌 ';
			badgeText  = confidence === 'high'
				? 'WordPress Plugin' + (name ? ' — ' + escHtml(name) : '')
				: 'Likely a WordPress Plugin';
		} else if (type === 'theme' && subtype === 'block') {
			badgeClass = confidence === 'high' ? 'gwp-detect-theme' : 'gwp-detect-theme gwp-detect-medium';
			icon       = '🎨 ';
			badgeText  = confidence === 'high'
				? 'Block Theme' + (name ? ' — ' + escHtml(name) : '')
				: 'Likely a Block Theme';
		} else if (type === 'theme') {
			badgeClass = confidence === 'high' ? 'gwp-detect-theme' : 'gwp-detect-theme gwp-detect-medium';
			icon       = '🎨 ';
			badgeText  = confidence === 'high'
				? 'Classic Theme' + (name ? ' — ' + escHtml(name) : '')
				: 'Likely a Classic Theme';
		} else {
			badgeClass = 'gwp-detect-unknown';
			icon       = '⚠ ';
			badgeText  = 'Not recognised as a WordPress project';
		}

		var html = '<span class="gwp-detect-badge ' + badgeClass + '">' + icon + badgeText + '</span>';

		if (!isKnown) {
			if (smartOn) {
				html += '<p class="gwp-detect-note gwp-detect-blocked">Smart Install is enabled — only verified plugins and themes can be installed. Disable Smart Install in Settings to override.</p>';
			} else {
				html += '<p class="gwp-detect-note gwp-detect-warn">This repository was not recognised as a WordPress plugin or theme. You can still install it at your own risk — choose a type below.</p>';
				$('#gwp-modal-type-row').show();
			}
		}

		$('#gwp-modal-detection').attr('class', 'gwp-detection-row').html(html);

		// Enable the Install button only if allowed
		var canInstall = isKnown || (!smartOn && !isKnown);
		$('#gwp-modal-install').prop('disabled', !canInstall);
	}

	$('#gwp-modal-cancel').on('click', function () {
		$('#gwp-branch-modal').hide();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			$('#gwp-branch-modal').hide();
		}
	});

	$('#gwp-modal-install').on('click', function () {
		var $btn   = $(this);
		var branch = $('#gwp-modal-branch-select').val();
		// Detected type takes priority; fall back to manual select (unknown repos only)
		var type   = _detectedType || $('#gwp-modal-type-select').val();

		if (!branch) {
			$('#gwp-modal-msg').removeClass('success error').addClass('error').text('Please select a branch.');
			return;
		}

		$btn.prop('disabled', true).html(spinner() + GHWP.i18n.installing);
		$('#gwp-modal-cancel').hide();
		$('#gwp-modal-msg').text('');

		$.post(GHWP.ajax_url, {
			action: 'gwp_install',
			nonce:  GHWP.nonce,
			owner:  _modalOwner,
			repo:   _modalRepo,
			branch: branch,
			type:   type,
		}).done(function (res) {
			if (res.success) {
				$btn.prop('disabled', true).text('Done');
				updateRepoCard(_modalFullName, res.data);
				startRedirectCountdown($('#gwp-modal-msg'), 3);
			} else {
				$('#gwp-modal-msg').removeClass('success').addClass('error').text(res.data);
				$btn.prop('disabled', false).text('Install');
				$('#gwp-modal-cancel').show();
			}
		}).fail(function () {
			$('#gwp-modal-msg').removeClass('success').addClass('error').text(GHWP.i18n.error);
			$btn.prop('disabled', false).text('Install');
			$('#gwp-modal-cancel').show();
		});
	});

	function updateRepoCard(fullName, record) {
		var $card = $('#gwp-repo-list .gwp-repo-card[data-full-name="' + fullName + '"]');
		if ($card.length) {
			$card.addClass('gwp-installed');
			$card.find('.gwp-repo-card-footer').html(
				'<span class="gwp-installed-tag">&#10003; Installed (' + escHtml(record.branch) + ')</span>'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Installed: load branches into select
	// -----------------------------------------------------------------------

	function loadBranchesIntoSelect(selectSelector, owner, repo, currentBranch) {
		var $select = $(selectSelector);
		$select.prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action: 'gwp_get_branches',
			nonce:  GHWP.nonce,
			owner:  owner,
			repo:   repo,
		}).done(function (res) {
			if (res.success && res.data.length) {
				var opts = res.data.map(function (b) {
					var sel = b === currentBranch ? ' selected' : '';
					return '<option value="' + escAttr(b) + '"' + sel + '>' + escHtml(b) + '</option>';
				});
				$select.html(opts.join(''));
			}
		}).always(function () {
			$select.prop('disabled', false);
		});
	}

	$(document).on('click', '.gwp-load-branches', function () {
		var $btn    = $(this);
		var owner   = $btn.data('owner');
		var repo    = $btn.data('repo');
		var $select = $btn.siblings('.gwp-branch-select');
		var current = $select.data('current');
		loadBranchesIntoSelect($select, owner, repo, current);
	});

	// -----------------------------------------------------------------------
	// Installed: switch branch on select change
	// -----------------------------------------------------------------------

	$(document).on('change', '.gwp-branch-select', function () {
		var $select    = $(this);
		var fullName   = $select.data('full-name');
		var newBranch  = $select.val();
		var $row       = $select.closest('tr');

		if (!confirm('Switch to branch "' + newBranch + '"? This will pull the branch from GitHub.')) {
			$select.val($select.data('current'));
			return;
		}

		$select.prop('disabled', true);
		$row.find('.gwp-update-btn, .gwp-remove-btn').prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action:     'gwp_switch_branch',
			nonce:      GHWP.nonce,
			full_name:  fullName,
			new_branch: newBranch,
		}).done(function (res) {
			if (res.success) {
				$select.data('current', newBranch);
				$row.find('.gwp-update-btn').data('branch', newBranch);
				showRowNotice($row, 'Switched to ' + newBranch, 'success');
			} else {
				$select.val($select.data('current'));
				showRowNotice($row, res.data, 'error');
			}
		}).fail(function () {
			$select.val($select.data('current'));
			showRowNotice($row, GHWP.i18n.error, 'error');
		}).always(function () {
			$select.prop('disabled', false);
			$row.find('.gwp-update-btn, .gwp-remove-btn').prop('disabled', false);
		});
	});

	// -----------------------------------------------------------------------
	// Installed: pull latest
	// -----------------------------------------------------------------------

	$(document).on('click', '.gwp-update-btn', function () {
		var $btn     = $(this);
		var fullName = $btn.data('full-name');
		var owner    = $btn.data('owner');
		var repo     = $btn.data('repo');
		var branch   = $btn.data('branch');
		var type     = $btn.data('type');
		var $row     = $btn.closest('tr');

		$btn.prop('disabled', true).html(spinner() + GHWP.i18n.updating);
		$row.find('.gwp-remove-btn').prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action: 'gwp_install',
			nonce:  GHWP.nonce,
			owner:  owner,
			repo:   repo,
			branch: branch,
			type:   type,
		}).done(function (res) {
			if (res.success) {
				showRowNotice($row, 'Updated successfully.', 'success');
			} else {
				showRowNotice($row, res.data, 'error');
			}
		}).fail(function () {
			showRowNotice($row, GHWP.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false).text('Pull Latest');
			$row.find('.gwp-remove-btn').prop('disabled', false);
		});
	});

	// -----------------------------------------------------------------------
	// Installed: remove
	// -----------------------------------------------------------------------

	$(document).on('click', '.gwp-remove-btn', function () {
		var $btn     = $(this);
		var fullName = $btn.data('full-name');

		if (!confirm(GHWP.i18n.confirm_remove)) {
			return;
		}

		$btn.prop('disabled', true).html(spinner() + GHWP.i18n.removing);

		var $row = $btn.closest('tr');

		$.post(GHWP.ajax_url, {
			action:    'gwp_remove',
			nonce:     GHWP.nonce,
			full_name: fullName,
		}).done(function (res) {
			if (res.success) {
				$row.fadeOut(300, function () { $(this).remove(); });
			} else {
				showRowNotice($row, res.data, 'error');
				$btn.prop('disabled', false).text('Remove');
			}
		}).fail(function () {
			showRowNotice($row, GHWP.i18n.error, 'error');
			$btn.prop('disabled', false).text('Remove');
		});
	});

	// -----------------------------------------------------------------------
	// Row-level notices
	// -----------------------------------------------------------------------

	function showRowNotice($row, text, type) {
		var color = type === 'success' ? '#1a7f37' : '#cf222e';
		var $cell = $row.find('td:last-child');
		var $notice = $('<span class="gwp-row-notice" style="display:block;font-size:12px;color:' + color + ';margin-top:4px">'
			+ escHtml(text) + '</span>');
		$cell.find('.gwp-row-notice').remove();
		$cell.append($notice);
		setTimeout(function () { $notice.fadeOut(500, function () { $(this).remove(); }); }, 4000);
	}

	// -----------------------------------------------------------------------
	// XSS helpers
	// -----------------------------------------------------------------------

	// -----------------------------------------------------------------------
	// Post-install redirect countdown
	// -----------------------------------------------------------------------

	function startRedirectCountdown($el, secs) {
		function tick() {
			if (secs <= 0) {
				$el.removeClass('error').addClass('success').text('Redirecting…');
				sessionStorage.setItem('gwp_goto_tab', 'installed');
				window.location.reload();
				return;
			}
			$el.removeClass('error')
			   .addClass('success')
			   .html('Installation complete — opening Installed tab in <strong>' + secs + '</strong>');
			secs--;
			setTimeout(tick, 1000);
		}
		tick();
	}

	// On page load: check sessionStorage for a pending tab redirect.
	(function () {
		var gotoTab = sessionStorage.getItem('gwp_goto_tab');
		if (gotoTab) {
			sessionStorage.removeItem('gwp_goto_tab');
			$('.gwp-tab[data-tab="' + gotoTab + '"]').trigger('click');
		}
	}());

	// -----------------------------------------------------------------------
	// XSS helpers
	// -----------------------------------------------------------------------

	function escHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function escAttr(s) {
		return escHtml(s);
	}

}(jQuery));
