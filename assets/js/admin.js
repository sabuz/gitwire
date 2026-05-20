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
		return '<span class="ghwp-spinner"></span>';
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

	$(document).on('click', '.ghwp-tab', function () {
		var tab = $(this).data('tab');
		$('.ghwp-tab').removeClass('active');
		$(this).addClass('active');
		$('.ghwp-panel').removeClass('active');
		$('#ghwp-tab-' + tab).addClass('active');

		// Auto-load repos when Browse is opened for the first time
		if (tab === 'browse' && $('#ghwp-repo-list .ghwp-repo-card').length === 0) {
			$('#ghwp-load-repos').trigger('click');
		}
	});

	// -----------------------------------------------------------------------
	// Settings: save
	// -----------------------------------------------------------------------

	$('#ghwp-save-settings').on('click', function () {
		if ( ! $('#ghwp-username').val().trim() ) {
			msg('#ghwp-settings-msg', 'GitHub Username is required.', 'error');
			$('#ghwp-username').focus();
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true);
		msg('#ghwp-settings-msg', '', '');

		$.post(GHWP.ajax_url, {
			action:        'ghwp_save_settings',
			nonce:         GHWP.nonce,
			token:         $('#ghwp-token').val(),
			username:      $('#ghwp-username').val(),
			smart_install: $('#ghwp-smart-install').is(':checked') ? 1 : 0,
		}).done(function (res) {
			if (res.success) {
				GHWP.smart_install = res.data.smart_install;
				msg('#ghwp-settings-msg', res.data.message, 'success');
				runTestConnection();
			} else {
				msg('#ghwp-settings-msg', res.data, 'error');
			}
		}).fail(function () {
			msg('#ghwp-settings-msg', GHWP.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// -----------------------------------------------------------------------
	// Settings: test connection
	// -----------------------------------------------------------------------

	$('#ghwp-test-connection').on('click', runTestConnection);

	function runTestConnection() {
		var $btn = $('#ghwp-test-connection');
		$btn.prop('disabled', true);
		renderStatusCard(null); // loading state

		$.post(GHWP.ajax_url, {
			action: 'ghwp_test_connection',
			nonce:  GHWP.nonce,
		}).done(function (res) {
			if (res.success) {
				renderStatusCard(res.data);
				if (!$('#ghwp-settings-msg').text()) {
					msg('#ghwp-settings-msg', 'Connected!', 'success');
				}
			} else {
				renderStatusCard({ error: res.data });
				msg('#ghwp-settings-msg', 'Connection failed: ' + res.data, 'error');
			}
		}).fail(function () {
			renderStatusCard({ error: GHWP.i18n.error });
			msg('#ghwp-settings-msg', GHWP.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	function renderStatusCard(d) {
		var $card = $('#ghwp-conn-status-card');

		if (d === null) {
			$card.html(
				'<div class="ghwp-conn-empty">'
				+ '<span class="ghwp-spinner" style="display:block;margin:0 auto 8px;"></span>'
				+ '<p>Checking connection…</p>'
				+ '</div>'
			);
			return;
		}

		if (d.error) {
			$card.html(
				'<div class="ghwp-conn-info" style="text-align:center">'
				+ '<div class="ghwp-conn-badge ghwp-conn-unauthenticated" style="display:inline-flex">'
				+ '<span class="dashicons dashicons-warning"></span> Connection failed'
				+ '</div>'
				+ '<p style="font-size:12px;color:#57606a;margin-top:8px">' + escHtml(d.error) + '</p>'
				+ '</div>'
			);
			return;
		}

		var html = '';

		if (d.login) {
			html += '<img src="' + escAttr(d.avatar_url) + '" alt="" class="ghwp-conn-avatar" />';
			html += '<div class="ghwp-conn-info">';
			html += '<div class="ghwp-conn-name">' + escHtml(d.name || d.login)
				+ '<span class="ghwp-conn-login">@' + escHtml(d.login) + '</span></div>';
			html += '<div class="ghwp-conn-badge ghwp-conn-authenticated">'
				+ '<span class="dashicons dashicons-yes-alt"></span> Authenticated</div>';
			html += '</div>';
		} else {
			html += '<div class="ghwp-conn-info">';
			html += '<div class="ghwp-conn-badge ghwp-conn-unauthenticated">'
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

			html += '<hr class="ghwp-conn-divider" />';
			html += '<div class="ghwp-rate-info">';
			html += '<div class="ghwp-rate-label"><span>API requests this hour</span>'
				+ '<strong>' + remaining.toLocaleString() + ' / ' + limit.toLocaleString() + '</strong></div>';
			html += '<div class="ghwp-rate-bar-track">'
				+ '<div class="ghwp-rate-bar ghwp-rate-bar--' + barClass + '" style="width:' + pct + '%"></div></div>';
			html += '<p class="ghwp-rate-note">' + escHtml(note) + '</p>';
			html += '</div>';
		}

		html += '<p class="ghwp-conn-checked">Just checked</p>';

		$card.html(html);
	}

	// -----------------------------------------------------------------------
	// Browse: load repos
	// -----------------------------------------------------------------------

	var currentPage = 1;

	function loadRepos(page, append) {
		var $list = $('#ghwp-repo-list');
		var $btn  = $('#ghwp-load-repos');

		if (!append) {
			$list.html('<p>' + spinner() + ' Loading repositories…</p>');
		}
		$btn.prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action: 'ghwp_get_repos',
			nonce:  GHWP.nonce,
			page:   page,
		}).done(function (res) {
			if (!res.success) {
				$list.html('<p class="ghwp-empty" style="color:#cf222e">' + res.data + '</p>');
				return;
			}

			if (!append) {
				$list.empty();
			}

			if (res.data.repos.length === 0 && !append) {
				$list.html('<p class="ghwp-empty">No repositories found.</p>');
				return;
			}

			res.data.repos.forEach(function (repo) {
				$list.append(buildRepoCard(repo));
			});

			applyFilter();

			// Queue detection for any cards that don't yet know their type.
			$('#ghwp-repo-list .ghwp-card-type-detecting').each(function () {
				var $b = $(this);
				if (!$b.data('queued')) {
					$b.data('queued', true);
					enqueueDetect($b.data('owner'), $b.data('repo'), $b.data('branch'), $b);
				}
			});

			currentPage = res.data.page;

			if (res.data.has_more) {
				$('#ghwp-load-more-wrap').show();
				$('#ghwp-load-more').data('page', currentPage + 1);
			} else {
				$('#ghwp-load-more-wrap').hide();
			}
		}).fail(function () {
			$list.html('<p class="ghwp-empty" style="color:#cf222e">' + GHWP.i18n.error + '</p>');
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
					action: 'ghwp_detect_repo',
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
			cls  = 'ghwp-card-type-plugin';
			text = 'Plugin';
		} else if (type === 'theme' && subtype === 'block') {
			cls  = 'ghwp-card-type-theme';
			text = 'Block Theme';
		} else if (type === 'theme') {
			cls  = 'ghwp-card-type-theme';
			text = 'Theme';
		} else {
			cls  = 'ghwp-card-type-unknown';
			text = 'Unknown';
		}

		$badge.attr('class', 'ghwp-card-type-badge ' + cls).text(text);
	}

	function buildTypeBadgeHtml(type, subtype) {
		var cls, text;
		if (type === 'plugin') {
			cls = 'ghwp-card-type-plugin'; text = 'Plugin';
		} else if (type === 'theme' && subtype === 'block') {
			cls = 'ghwp-card-type-theme';  text = 'Block Theme';
		} else if (type === 'theme') {
			cls = 'ghwp-card-type-theme';  text = 'Theme';
		} else {
			cls = 'ghwp-card-type-unknown'; text = 'Unknown';
		}
		return '<span class="ghwp-card-type-badge ' + cls + '">' + text + '</span>';
	}

	// -----------------------------------------------------------------------

	function buildRepoCard(repo) {
		var isInstalled = !!repo.installed;
		var cls = 'ghwp-repo-card' + (isInstalled ? ' ghwp-installed' : '');
		var owner = repo.full_name.split('/')[0];

		var visibilityBadge = repo.private
			? '<span class="ghwp-private-badge">Private</span>'
			: '<span class="ghwp-public-badge">Public</span>';

		// Type badge: known immediately for installed repos, queued for detection otherwise.
		var typeBadge;
		if (isInstalled) {
			typeBadge = buildTypeBadgeHtml(repo.installed.type, null);
		} else {
			typeBadge = '<span class="ghwp-card-type-badge ghwp-card-type-detecting"'
				+ ' data-owner="' + escAttr(owner) + '"'
				+ ' data-repo="'  + escAttr(repo.name) + '"'
				+ ' data-branch="' + escAttr(repo.default_branch) + '">'
				+ '<span class="ghwp-spinner ghwp-spinner-xs"></span>'
				+ '</span>';
		}

		var desc = repo.description
			? '<p class="ghwp-repo-desc">' + escHtml(repo.description) + '</p>'
			: '';

		var stars = repo.stargazers_count > 0
			? '<span><span class="dashicons dashicons-star-filled"></span> ' + repo.stargazers_count + '</span>'
			: '';

		var updated = repo.updated_at
			? '<span>Updated: ' + humanDate(repo.updated_at) + '</span>'
			: '';

		var footer = '';
		if (isInstalled) {
			footer = '<span class="ghwp-installed-tag">&#10003; Installed (' + escHtml(repo.installed.branch) + ')</span>';
		} else {
			footer = '<button class="button button-small ghwp-install-btn button-primary" '
				+ 'data-owner="' + escAttr(owner) + '" '
				+ 'data-repo="'  + escAttr(repo.name) + '" '
				+ 'data-default-branch="' + escAttr(repo.default_branch) + '" '
				+ 'data-full-name="' + escAttr(repo.full_name) + '">'
				+ 'Install</button>';
		}

		return '<div class="' + cls + '" data-full-name="' + escAttr(repo.full_name) + '">'
			+ '<div class="ghwp-repo-card-header">'
			+ '<div class="ghwp-repo-name"><a href="' + escAttr(repo.html_url) + '" target="_blank" rel="noopener">' + escHtml(repo.full_name) + '</a></div>'
			+ '<div class="ghwp-card-badges">' + visibilityBadge + typeBadge + '</div>'
			+ '</div>'
			+ desc
			+ '<div class="ghwp-repo-meta">' + stars + updated + '</div>'
			+ '<div class="ghwp-repo-card-footer">' + footer + '</div>'
			+ '</div>';
	}

	$('#ghwp-load-repos').on('click', function () {
		loadRepos(1, false);
	});

	$('#ghwp-load-more').on('click', function () {
		loadRepos($(this).data('page'), true);
	});

	// -----------------------------------------------------------------------
	// Browse: filter
	// -----------------------------------------------------------------------

	$('#ghwp-search').on('input', applyFilter);

	function applyFilter() {
		var q = $('#ghwp-search').val().toLowerCase();
		$('#ghwp-repo-list .ghwp-repo-card').each(function () {
			var name = $(this).data('full-name').toLowerCase();
			$(this).toggle(!q || name.indexOf(q) !== -1);
		});
	}

	// -----------------------------------------------------------------------
	// Install modal
	// -----------------------------------------------------------------------

	var _modalOwner, _modalRepo, _modalFullName, _detectedType;

	$(document).on('click', '.ghwp-install-btn', function () {
		var $btn = $(this);
		_modalOwner    = $btn.data('owner');
		_modalRepo     = $btn.data('repo');
		_modalFullName = $btn.data('full-name');
		_detectedType  = null;
		var defaultBranch = $btn.data('default-branch') || 'main';

		// Open modal in loading/detecting state
		$('#ghwp-modal-repo-name').text(_modalFullName);
		$('#ghwp-modal-branch-select').html('<option value="' + escAttr(defaultBranch) + '" selected>' + escHtml(defaultBranch) + '</option>');
		$('#ghwp-modal-msg').removeClass('success error').text('');
		$('#ghwp-modal-install').prop('disabled', true).text('Install');
		$('#ghwp-modal-type-row').hide();
		$('#ghwp-modal-detection')
			.attr('class', 'ghwp-detection-row ghwp-detection-loading')
			.html('<span class="ghwp-spinner"></span> ' + GHWP.i18n.detecting);

		$('#ghwp-branch-modal').show();

		// Load branches in parallel with detection
		loadBranchesIntoSelect('#ghwp-modal-branch-select', _modalOwner, _modalRepo);

		// Detect project type
		$.post(GHWP.ajax_url, {
			action: 'ghwp_detect_repo',
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
			badgeClass = confidence === 'high' ? 'ghwp-detect-plugin' : 'ghwp-detect-plugin ghwp-detect-medium';
			icon       = '🔌 ';
			badgeText  = confidence === 'high'
				? 'WordPress Plugin' + (name ? ' — ' + escHtml(name) : '')
				: 'Likely a WordPress Plugin';
		} else if (type === 'theme' && subtype === 'block') {
			badgeClass = confidence === 'high' ? 'ghwp-detect-theme' : 'ghwp-detect-theme ghwp-detect-medium';
			icon       = '🎨 ';
			badgeText  = confidence === 'high'
				? 'Block Theme' + (name ? ' — ' + escHtml(name) : '')
				: 'Likely a Block Theme';
		} else if (type === 'theme') {
			badgeClass = confidence === 'high' ? 'ghwp-detect-theme' : 'ghwp-detect-theme ghwp-detect-medium';
			icon       = '🎨 ';
			badgeText  = confidence === 'high'
				? 'Classic Theme' + (name ? ' — ' + escHtml(name) : '')
				: 'Likely a Classic Theme';
		} else {
			badgeClass = 'ghwp-detect-unknown';
			icon       = '⚠ ';
			badgeText  = 'Not recognised as a WordPress project';
		}

		var html = '<span class="ghwp-detect-badge ' + badgeClass + '">' + icon + badgeText + '</span>';

		if (!isKnown) {
			if (smartOn) {
				html += '<p class="ghwp-detect-note ghwp-detect-blocked">Smart Install is enabled — only verified plugins and themes can be installed. Disable Smart Install in Settings to override.</p>';
			} else {
				html += '<p class="ghwp-detect-note ghwp-detect-warn">This repository was not recognised as a WordPress plugin or theme. You can still install it at your own risk — choose a type below.</p>';
				$('#ghwp-modal-type-row').show();
			}
		}

		$('#ghwp-modal-detection').attr('class', 'ghwp-detection-row').html(html);

		// Enable the Install button only if allowed
		var canInstall = isKnown || (!smartOn && !isKnown);
		$('#ghwp-modal-install').prop('disabled', !canInstall);
	}

	$('#ghwp-modal-cancel').on('click', function () {
		$('#ghwp-branch-modal').hide();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			$('#ghwp-branch-modal').hide();
		}
	});

	$('#ghwp-modal-install').on('click', function () {
		var $btn   = $(this);
		var branch = $('#ghwp-modal-branch-select').val();
		// Detected type takes priority; fall back to manual select (unknown repos only)
		var type   = _detectedType || $('#ghwp-modal-type-select').val();

		if (!branch) {
			$('#ghwp-modal-msg').removeClass('success error').addClass('error').text('Please select a branch.');
			return;
		}

		$btn.prop('disabled', true).html(spinner() + GHWP.i18n.installing);
		$('#ghwp-modal-cancel').hide();
		$('#ghwp-modal-msg').text('');

		$.post(GHWP.ajax_url, {
			action: 'ghwp_install',
			nonce:  GHWP.nonce,
			owner:  _modalOwner,
			repo:   _modalRepo,
			branch: branch,
			type:   type,
		}).done(function (res) {
			if (res.success) {
				$btn.prop('disabled', true).text('Done');
				updateRepoCard(_modalFullName, res.data);
				startRedirectCountdown($('#ghwp-modal-msg'), 3);
			} else {
				$('#ghwp-modal-msg').removeClass('success').addClass('error').text(res.data);
				$btn.prop('disabled', false).text('Install');
				$('#ghwp-modal-cancel').show();
			}
		}).fail(function () {
			$('#ghwp-modal-msg').removeClass('success').addClass('error').text(GHWP.i18n.error);
			$btn.prop('disabled', false).text('Install');
			$('#ghwp-modal-cancel').show();
		});
	});

	function updateRepoCard(fullName, record) {
		var $card = $('#ghwp-repo-list .ghwp-repo-card[data-full-name="' + fullName + '"]');
		if ($card.length) {
			$card.addClass('ghwp-installed');
			$card.find('.ghwp-repo-card-footer').html(
				'<span class="ghwp-installed-tag">&#10003; Installed (' + escHtml(record.branch) + ')</span>'
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
			action: 'ghwp_get_branches',
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

	$(document).on('click', '.ghwp-load-branches', function () {
		var $btn    = $(this);
		var owner   = $btn.data('owner');
		var repo    = $btn.data('repo');
		var $select = $btn.siblings('.ghwp-branch-select');
		var current = $select.data('current');
		loadBranchesIntoSelect($select, owner, repo, current);
	});

	// -----------------------------------------------------------------------
	// Installed: switch branch on select change
	// -----------------------------------------------------------------------

	$(document).on('change', '.ghwp-branch-select', function () {
		var $select    = $(this);
		var fullName   = $select.data('full-name');
		var newBranch  = $select.val();
		var $row       = $select.closest('tr');

		if (!confirm('Switch to branch "' + newBranch + '"? This will pull the branch from GitHub.')) {
			$select.val($select.data('current'));
			return;
		}

		$select.prop('disabled', true);
		$row.find('.ghwp-update-btn, .ghwp-remove-btn').prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action:     'ghwp_switch_branch',
			nonce:      GHWP.nonce,
			full_name:  fullName,
			new_branch: newBranch,
		}).done(function (res) {
			if (res.success) {
				$select.data('current', newBranch);
				$row.find('.ghwp-update-btn').data('branch', newBranch);
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
			$row.find('.ghwp-update-btn, .ghwp-remove-btn').prop('disabled', false);
		});
	});

	// -----------------------------------------------------------------------
	// Installed: pull latest
	// -----------------------------------------------------------------------

	$(document).on('click', '.ghwp-update-btn', function () {
		var $btn     = $(this);
		var fullName = $btn.data('full-name');
		var owner    = $btn.data('owner');
		var repo     = $btn.data('repo');
		var branch   = $btn.data('branch');
		var type     = $btn.data('type');
		var $row     = $btn.closest('tr');

		$btn.prop('disabled', true).html(spinner() + GHWP.i18n.updating);
		$row.find('.ghwp-remove-btn').prop('disabled', true);

		$.post(GHWP.ajax_url, {
			action: 'ghwp_install',
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
			$row.find('.ghwp-remove-btn').prop('disabled', false);
		});
	});

	// -----------------------------------------------------------------------
	// Installed: remove
	// -----------------------------------------------------------------------

	$(document).on('click', '.ghwp-remove-btn', function () {
		var $btn     = $(this);
		var fullName = $btn.data('full-name');

		if (!confirm(GHWP.i18n.confirm_remove)) {
			return;
		}

		$btn.prop('disabled', true).html(spinner() + GHWP.i18n.removing);

		var $row = $btn.closest('tr');

		$.post(GHWP.ajax_url, {
			action:    'ghwp_remove',
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
		var $notice = $('<span class="ghwp-row-notice" style="display:block;font-size:12px;color:' + color + ';margin-top:4px">'
			+ escHtml(text) + '</span>');
		$cell.find('.ghwp-row-notice').remove();
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
				sessionStorage.setItem('ghwp_goto_tab', 'installed');
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
		var gotoTab = sessionStorage.getItem('ghwp_goto_tab');
		if (gotoTab) {
			sessionStorage.removeItem('ghwp_goto_tab');
			$('.ghwp-tab[data-tab="' + gotoTab + '"]').trigger('click');
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
