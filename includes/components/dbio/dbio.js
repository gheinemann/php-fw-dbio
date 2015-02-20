(function(global, hbs, moment) {
	var DBIO = (function() {
		var _self = this;

		_self._remotes = {};
		_self._report = null;
		_self.ajax_request_limit = 3;

		_self.return_types = {
			DBIO_RESULT_VALID: "dbio_result_valid",
			DBIO_RESULT_ERROR: "dbio_result_error"
		};

		_self.createRemote = function (pElement, pOptions) {
			var Monitor,
				returnMonitor = null,
				settings,
				reportSelector,
				stopSelector;

			settings = pOptions;

			if (!settings.urls && !settings.urls.process_job) {
				console.log('DBIO createMonitor : a process_job url is missing');
				return null;
			}

			reportSelector = pElement.getAttribute('data-report-element');
			stopSelector = pElement.getAttribute('data-stop-element');

			Monitor = new function() {
				var _m = this;

				_m.report = _self.createReport(reportSelector, pElement, pOptions);
				_m.stopEl = document.querySelector(stopSelector);

				_m.url_process = settings.urls.process_job;
				_m.jobs_pool = settings.jobs_pool;
				_m.jobs_line = [];
				_m.params = settings.params;
				_m.intervalMs = 30000;

				_m.timer = {};

				_m.jobs_line_current_index = null;
				_m.jobs_line_last_index = null;
				_m.jobs_line_done = 0;

				_m.init = function() {
					for (var job_pool_id in _m.jobs_pool) {
						if (!_m.jobs_pool.hasOwnProperty(job_pool_id)) {
							//The current property is not a direct property of p
							continue;
						}

						_m.jobs_line.push(job_pool_id);
					}

					_m.jobs_line_current_index = 0;
					_m.jobs_line_last_index = _m.jobs_line.length - 1;

					pElement.addEventListener('click', _m.remoteClickHandler);
					// define stop current process button
					if (_m.stopEl) {
						_m.stopEl.addEventListener('click', _m.stopClickHandler);
					}

				};

				_m.jobLineLaunchJob = function (pSkipIncrement) {
					if (!!!pSkipIncrement) {
						_m.jobs_line_current_index++;
					}

					if (!_m.jobs_line_stop_process) {
						_m.processJob(_m.jobs_line[_m.jobs_line_current_index]);
					}
				};

				_m.processJob = function(pDbioJobDetails, pSingle) {
					(function (job_detail) {
						qwest.before(function() {
							_m.report.jobStart(job_detail);
							}).post(
								_m.url_process,
								{
									dbio_job_details: job_detail
								},
								{
									responseType: 'json',
									cache: false,
									timeout: 300000,
									retries: 5
								}).then(function(response){
									//console.log(response);
									if (response.type) {
										switch(response.type) {
											case _self.return_types.DBIO_RESULT_VALID:
											case _self.return_types.DBIO_RESULT_ERROR:
												_m.report.jobDone(job_detail, response);
												break;
											default:
												_m.report.jobDone(job_detail, {
													type:_self.return_types.DBIO_RESULT_ERROR,
													msg: "Type de réponse non supporté ("+response.type+")"
												});
												break;
										}
									} else {
										_m.report.jobDone(job_detail, {
											type:_self.return_types.DBIO_RESULT_ERROR,
											msg: "Pas de réponse typée"
										});
									}
								}).catch(function(message){
									//console.log(message);
									_m.report.jobDone(job_detail, {
										type:_self.return_types.DBIO_RESULT_ERROR,
										msg: message
									});
								}).complete(function() {
									if (pSingle === undefined) {
										++_m.jobs_line_done;
										console.log(_m.jobs_line_current_index, _m.jobs_line_last_index, _m.jobs_line_done);

										if (_m.jobs_line_done === _m.jobs_line_last_index+1
											|| _m.jobs_line_current_index === _m.jobs_line_last_index) {
											_m.jobs_line_stop_process = true;
										}

										//if (_m.jobs_line_done === _m.jobs_line_last_index + 1) {
										//	_m.jobs_line_stop_process = true;
										//}

										if (!_m.jobs_line_stop_process) {
											_m.jobLineLaunchJob();
										} else {
											_m.jobs_line_running = false;
											_m.stopTimer();
										}
									}
								});
					})(pDbioJobDetails);

				};

				_m.processAllJobs = function() {
					var incr_flag = true,
						maxLaunch;

					// reset report analytics
					_m.report.resetAnalytics();

					// reset job_line
					_m.jobs_line_current_index = 0;
					_m.jobs_line_done = 0;

					// reset stop current process flag
					_m.jobs_line_stop_process = false;
					_m.jobs_line_running = true;

					maxLaunch = _self.ajax_request_limit > _m.jobs_line.length ? _m.jobs_line.length : _self.ajax_request_limit;

					_m.startTimer();

					for (var i = 0; i < maxLaunch; i++) {
						if (i > 0) {
							incr_flag = false;
						}
						_m.jobLineLaunchJob(incr_flag);
					}

				};

				_m.startTimer = function() {
					_m.timer.start = moment();
					_m.majTotalTime();

					_m.timer.id = setInterval(function() {
						_m.majTotalTime();
					}, _m.intervalMs);
				};

				_m.stopTimer = function() {
					clearInterval(_m.timer.id);
					_m.majTotalTime(true);
				};

				_m.majTotalTime = function(lastFlag) {
					var diffTime = moment().subtract(_m.timer.start),
						display = '';

					if (!!!lastFlag) {
						display += ' ~ ';
					}

					if (diffTime.hours() > 0) {
						display += diffTime.format('H') + ' h';
					}
					if (diffTime.minutes() > 0) {
						display += ' ' + diffTime.format('m') + ' min';
					}

					display += ' ' + diffTime.format('ss');
					if (!!lastFlag) {
						display += '.' + diffTime.format('SS');
					}
					display += ' sec';

					_m.report.majTotalTimeEl(display);
				};

				_m.stopClickHandler = function(e) {
					e.preventDefault();

					if (_m.jobs_line_running) {
						_m.jobs_line_stop_process = true;
					}

					return false;
				};

				_m.remoteClickHandler = function (e) {
					e.preventDefault();

					_m.processAllJobs();

					return false;
				};

				_m.init();

				return _m;
			};

			returnMonitor = {
				jobs_pool: Monitor.jobs_pool,
				jobs_line: Monitor.jobs_line,
				processAllJobs: Monitor.processAllJobs,
				processJob: Monitor.processJob
			};


			return returnMonitor;
		};

		_self.createRemoteFromElement = function(pElement) {
			var data = JSON.parse(pElement.getAttribute('data-dbio'));

			return _self.createRemote(pElement, data);
		};

		_self.createReport = function (pIdContainer, pEl, pData) {
			var Report = function() {
				var _r = this;

				_r.id = pIdContainer;
				_r.linkedEl = pEl;
				_r.data = pData;

				_r.elReportContainer = null;
				_r.elReportJobsContainer = null;
				_r.elReportAnalytics = null;

				_r.jobs = [];

				//analytics data
				_r.analytics = {
					tuple_nb : 0,
					insert_nb : 0,
					success_inserts : 0,
					errors_inserts : 0,
					percentage_progress : 0,
					percentage_success : 0
				};

				// templates
				_r.tpl_container = hbs.compile('<div id="{{ id }}" class="report">' +
													'<h3>Rapport</h3>' +
												'</div>');
				_r.tpl_jobs_container = hbs.compile('<div class="results-global">' +
														'<input id="job_results_switch" type="checkbox" class="cb-toggle-switch" />' +
														'<div class="cb-toggle-container">' +
															'<label class="cb-toggle cb-toggle-initial-state" for="job_results_switch">Cacher le détail du rapport</label>' +
															'<label class="cb-toggle cb-toggle-final-state" for="job_results_switch">Voir le détail du rapport</label>' +
														'</div>' +
													'</div>');
				_r.tpl_analytics = hbs.compile('<div class="analytics-global">' +
													'<div class="analytics-progress" style="width:{{percentage_progress}}%;"></div>' +
													'<div class="analytics-progress-value">{{percentage_progress}}%</div>' +
													'<div class="row">' +
														'<div class="columns three">' +
															'<p class="analytics-tuple-nb">Nombre d\'entrées: {{tuple_nb}}</p>' +
															'<p class="analytics-insert-nb">Nombre d\'insertions: {{insert_nb}}</p>' +
														'</div>' +
														'<div class="columns three">' +
															'<p class="analytics-success">Insertions avec succès: <span class="value"></span></p>' +
															'<p class="analytics-errors">Erreurs: <span class="value"></span></p>' +
															'<p class="analytics-percentage-success">Pourcentage de réussite: <span class="value"></span></p>' +
														'</div>' +
														'<p class="analytics-global-time">Temps total: <span class="value"></span></p>' +
													'</div>' +
												'</div>');

				_r.tpl_jobs = hbs.compile('<div class=" cb-toggle-target">' +
											'{{#each groups}}' +
													'<ul id="{{ id }}" class="report-jobs">' +
													'{{#each jobs}}' +
														'<li id="{{ id }}" class="report-job process-ready">' +
															'<p class="job-label">{{ label }}</p>' +
															'<p class="job-report"></p>' +
															'<span class="job-icon"></span>' +
															'<input type="checkbox" id="{{ id }}_result" class="cb-toggle-switch">' +
															'<div class="job-action-bar cb-toggle-container">' +
																'<form class="config-button-container" target="_blank" action="{{generate_config_url}}" method="post">' +
																	'<input type="hidden" name="config_file_name" value="{{config_file_name}}" />' +
																	'<input type="hidden" name="target_jobs" value="{{job_json}}" />' +
																	'<button class="config-button" title="Télécharger la config pour ce job"></button>' +
																'</form>' +
																'<label for="{{ id }}_result" class="cb-toggle cb-toggle-initial-state">Détails</label>' +
																'<label for="{{ id }}_result" class="cb-toggle cb-toggle-final-state">Détails</label>' +
															'</div>' +

															'<div class="cb-toggle-target job-details"></div>' +
														'</li>' +
													'{{/each}}' +
												'</ul>' +
											'{{/each}}' +
										'</ul>');
				_r.tpl_process_details = hbs.compile('{{#if message}}<p class="job-details-msg">Erreur : {{ message }}</p>{{/if}}' +
														'{{#if sql}}<div class="job-details-sql">' +
															'<strong>SQL</strong> :' +
															'<div class="job-details-sql-container">' +
																'<span class="pre-label">SQL</span>' +
																'<pre>{{ sql }}</pre>' +
															'</div>' +
														'</div>{{/if}}');

				_r.init = function () {

					if (!_r.id) {
						throw new Error('No container id specified for the report');
					}

					// set jobs
					_r.jobs = _r.data.jobs_pool;

					// get or create report container
					_r.elReportContainer = document.querySelector(_r.id) || _r.createElementFromHtmlString(_r.tpl_container({
						id : _r.id.replace('#', '')
					}));

					// create jobs container
					_r.elReportJobsContainer = _r.createElementFromHtmlString(_r.tpl_jobs_container());
					// add jobs container + analytics to report container
					_r.elReportContainer.insertBefore(_r.elReportJobsContainer, null);


					/* LOOP ON JOBS */
					last_job_id = null;
					tmp_group = [];
					tmp_groups = {};
					template_groups = [];

					for(var job_id in _r.jobs) {
						var pJob = _r.jobs[job_id],
							pGenUrl,
							formatted_job = {};

						// maj global analytics data
						_r.analytics.insert_nb++;
						_r.analytics.tuple_nb += pJob.offset_end - pJob.offset_from_start + 1;

						if (_r.data.urls && _r.data.urls.generate_config) {
							pGenUrl = _r.data.urls.generate_config;
						}

						formatted_job.id = _r.getJobElementSelector(job_id);
						formatted_job.label = pJob.job_id + ' : ' + pJob.offset_from_start + ' -> ' + pJob.offset_end;
						var tmp_json = {};
						tmp_json[pJob.job_id] = {
							params: pJob
						};

						formatted_job.job_json = JSON.stringify(tmp_json);
						formatted_job.generate_config_url = pGenUrl;
						formatted_job.config_file_name = _r.data.config_file_name;

						if (!tmp_groups[pJob.job_id]) {
							tmp_groups[pJob.job_id] = [];
						}

						tmp_groups[pJob.job_id].push(formatted_job);
					}

					for (var id in tmp_groups) {
						template_groups.push({
							id : id ,
							jobs : tmp_groups[id]
						});
					}

					_r.elReportJobsContainer.insertBefore(_r.createElementFromHtmlString(_r.tpl_jobs({groups: template_groups})), null);
					/* ------------------LOOP ON JOBS */


					// create analytics container + add to report container
					_r.elReportAnalytics = _r.createElementFromHtmlString(_r.tpl_analytics(_r.analytics));
					_r.elReportContainer.insertBefore(_r.elReportAnalytics, _r.elReportContainer.firstElementChild);


					// add report container to page
					if (_r.linkedEl) {
						_r.linkedEl.parentElement.insertBefore(_r.elReportContainer, null);
					} else {
						document.body.insertBefore(_r.elReportContainer, null);
					}
				};

				_r.createContainer = function(pId, pRefElement) {
					elRef = pRefElement !== null && pRefElement !== undefined ? pRefElement.parentElement : document.body;

					return _r.createElementFromHtmlString();
				};

				_r.createElementFromHtmlString = function(pHtmlString) {
					var tmp_el;
					tmp_el = document.createElement('div');
					tmp_el.innerHTML = pHtmlString;
					tmp_el = tmp_el.firstElementChild;

					return tmp_el;
				};

				_r.majProcessReport = function(pEl, pData) {
					if (pData) {
						pEl.innerHTML = pData.total_time.toFixed(3) + ' sec&nbsp;&nbsp;(~' + (pData.total_time / pData.tuple_number).toFixed(3) + ' sec / entrée)';
					}
				};

				_r.majProcessDetails = function(pEl, pData) {
					var tpl_data = {};

					if (pEl) {
						if (pData.type === _self.return_types.DBIO_RESULT_ERROR) {
							tpl_data.message = pData.msg;
						}
						if (pData.sql) {
							tpl_data.sql = pData.sql;
						}


						pEl.innerHTML = _r.tpl_process_details(tpl_data);
					}
				};

				_r.jobStart = function(pId) {
					var jobEl = _r.getJobElementFromJobId(pId);

					jobEl.classList.remove('process-ready');
					jobEl.classList.remove('process-running');
					jobEl.classList.remove('process-error');
					jobEl.classList.remove('process-valid');
					jobEl.classList.add('process-running');
					jobEl.querySelector('.cb-toggle-switch').checked = false;
				};

				_r.jobDone = function(pId, pReturnDatas) {
					_r.updateJob(pId, pReturnDatas);
				};

				_r.updateJob = function(pId, pReturnDatas) {
					var jobEl = _r.getJobElementFromJobId(pId),
						majStateclass;

					if (!jobEl) {
						console.log('No jobElement for ' + pId);
						return;
					}

					if (!pReturnDatas.type) {
						console.log('No return type from DBIO for ' + pId);
						return;
					}

					switch (pReturnDatas.type) {
						case _self.return_types.DBIO_RESULT_VALID:
							majStateclass = 'process-valid';
							_r.analytics.success_inserts++;
							break;
						case _self.return_types.DBIO_RESULT_ERROR:
							majStateclass = 'process-error';
							_r.analytics.errors_inserts++;
							break;
						default:
							console.log('Invalid return type from DBIO for ' + pId + ' (' +pReturnDatas.type+ ')');
							return;
							break;
					}

					jobEl.classList.remove('process-ready');
					jobEl.classList.remove('process-running');
					jobEl.classList.remove('process-error');
					jobEl.classList.remove('process-valid');
					jobEl.classList.add(majStateclass);

					_r.majProcessReport(jobEl.querySelector('.job-report'), pReturnDatas.report);
					_r.majProcessDetails(jobEl.querySelector('.job-details'), pReturnDatas);

					_r.analytics.percentage_progress = ((_r.analytics.success_inserts + _r.analytics.errors_inserts) / _r.analytics.insert_nb) * 100;
					_r.analytics.percentage_success = (_r.analytics.success_inserts / _r.analytics.insert_nb) * 100;

					_r.majAnalyticsEl();
				};

				_r.majAnalyticsEl = function() {
					var progressBar,
						addClassFlag = false,
						className;

					_r.elReportAnalytics.querySelector('.analytics-success .value').innerHTML = _r.analytics.success_inserts;
					_r.elReportAnalytics.querySelector('.analytics-errors .value').innerHTML = _r.analytics.errors_inserts;
					_r.elReportAnalytics.querySelector('.analytics-percentage-success .value').innerHTML = _r.analytics.percentage_success.toFixed(2) + '%';
					_r.elReportAnalytics.querySelector('.analytics-progress-value').innerHTML = parseInt(_r.analytics.percentage_progress) + '%';

					progressBar = _r.elReportAnalytics.querySelector('.analytics-progress');
					progressBar.style.width = _r.analytics.percentage_progress + '%';


					if (_r.analytics.percentage_progress > 90) {
						addClassFlag = true;
						className = 'complete';
					} else {
						if (_r.analytics.percentage_progress > 60) {
							addClassFlag = true;
							className = 'half';
						}
					}

					progressBar.classList.remove('half');
					progressBar.classList.remove('complete');

					if (addClassFlag) {
						progressBar.classList.add(className);
					}

				};

				_r.majTotalTimeEl = function(pTime) {
					_r.elReportAnalytics.querySelector('.analytics-global-time .value').innerHTML = pTime;
				};

				_r.resetAnalytics = function() {
					_r.analytics.success_inserts = 0;
					_r.analytics.errors_inserts = 0;
					_r.analytics.percentage_progress = 0;
					_r.analytics.percentage_success = 0;

					_r.majAnalyticsEl();
				};

				_r.getJobElementFromJobId = function(pId) {
					var job_name = _r.getJobElementSelector(pId);

					return document.querySelector('#' + job_name);
				};

				_r.getJobElementSelector = function(pId) {
					return pId.replace(/\//g, '_');
				};

				_r.getJobNameFromId = function (pId) {
					return pId.substr(0, pId.indexOf("/"));
				};

				_r.init();

				return _r;
			};

			return new Report();
		};

		return {
			createRemote : _self.createRemote,
			//getRemote : _self.getRemote,
			createRemoteFromElement : _self.createRemoteFromElement
			//getReport: _self.getReport
		};
	})();

	window.DBIO = DBIO;
})(window, Handlebars, moment);

function init_dbio(e) {
	document.querySelectorAll('[data-dbio]').forEach(function(el, index, list) {
		var dbio_remote = DBIO.createRemoteFromElement(el);


		//var data = JSON.parse(el.getAttribute('data-dbio'));
		//console.log(data);
	});
}

document.addEventListener('DOMContentLoaded', init_dbio);
