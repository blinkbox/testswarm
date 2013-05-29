/**
 * JavaScript file to included by the test suite page that it loaded
 * inside the iframe on the "run" pages. This injection must be done
 * by the guest page, it can't be loaded by TestSwarm.
 * Example:
 * - https://github.com/jquery/jquery/blob/master/test/data/testrunner.js
 * - https://github.com/jquery/jquery/blob/master/test/index.html
 *
 * @author John Resig, 2008-2011
 * @author Timo Tijhof, 2012
 * @since 0.1.0
 * @package TestSwarm
 */
/*global QUnit, Test, JSSpec, JsUnitTestManager, SeleniumTestResult, LOG, doh, Screw, mocha */
(function (undefined) {
	var	DEBUG, doPost, search, url, index, submitTimeout, 
		beatRate, defaultBeatRate, testFrameworks, onErrorFnPrev;

	DEBUG = false;

	doPost = false;
	search = window.location.search;
	index = search.indexOf( 'swarmURL=' );
	submitTimeout = 5;
	defaultBeatRate = beatRate = 20;
	try {
		doPost = !!window.parent.postMessage;
	} catch ( e ) {}

	if ( index !== -1 ) {
		url = decodeURIComponent( search.slice( index + 9 ) );
	}

	if ( !DEBUG && ( !url || url.indexOf( 'http' ) !== 0 ) ) {
		return;
	}

	// Prevent blocking things from executing
	if ( !DEBUG ) {
		window.print = window.confirm = window.alert = window.open = function () {};
	}
	
	var css = document.createElement('link'), 
		baseUrl = url.split('index.php?')[0];
	css.rel = 'stylesheet';
	css.href = baseUrl + 'css/runner.css';
	document.getElementsByTagName('head')[0].appendChild(css);
	
	/** Utility functions **/

	function debugObj( obj ) {
		var i, str = '';
		for ( i in obj ) {
			str += ( str ? '\n' : '' ) + i + ':\n\t ' + obj[i];
		}
		return str;
	}

	function remove( elem ) {
		if ( typeof elem === 'string' ) {
			elem = document.getElementById( elem );
		}

		if ( elem ) {
			elem.parentNode.removeChild( elem );
		}
	}

	function trimSerialize( doc ) {
		var scripts, root, cur, links, i, href;
		doc = doc || document;

		scripts = doc.getElementsByTagName( 'script' );
		while ( scripts.length ) {
			remove( scripts[0] );
		}

		root = window.location.href.replace( /(https?:\/\/.*?)\/.*/, '$1' );
		cur = window.location.href.replace( /[^\/]*$/, '' );

		links = doc.getElementsByTagName( 'link' );
		for ( i = 0; i < links.length; i += 1 ) {
			// links[i].href is returning full url in chrome even if href attribute is relative
			//href = links[i].href;
			// reading the href attribute returns href string is it's set on the element
			href = links[i].attributes['href'].value;
			
			if ( href.indexOf( '/' ) === 0 ) {
				href = root + href;
			} else if ( !/^https?:\/\//.test( href ) ) {
				href = cur + href;
			}
            
			if(links[i].attributes['href'].value != href){
				// if href was changed we need to put it back to DOM
			    links[i].attributes['href'].value = href;				
				//log('new href is ' + href);
            }
		}

		return ( '<html>' + doc.documentElement.innerHTML + '</html>' )
			.replace( /\s+/g, ' ' );
	}

	function log(message)
	{
		var span = document.createElement( 'span' );
		if (typeof message === 'string') {
			span.innerText = message;
		} 
		if (typeof message === 'object') {
			span.innerText = message.message;
		}
		
		var strong = document.createElement( 'strong' );
		strong.innerText = ( function getDate() {
		
			function pad(num, size) {
				var s = num+'';
				while (s.length < size) s = '0' + s;
				return s;
			}
		
			var now = new Date();
			
			return 	now.getFullYear() + '/' + 
					pad( now.getMonth() + 1, 2 ) + '/' + 
					pad( now.getDate(), 2 ) + ' ' + 
					pad( now.getHours(), 2 ) + ':' + 
					pad( now.getMinutes(), 2 ) + ':' + 
					pad( now.getSeconds(), 2 ) + '.' +
					pad( now.getMilliseconds(), 4 ) + ': ';
		} ) ();
		
		var li = document.createElement( 'li' );
		if (typeof message === 'object') {
			li.className = message.cssClass;
		}
		li.appendChild(strong);
		li.appendChild(span);
		//getLogger().appendChild(li);
		var ul = getLogger();
		ul.insertBefore(li, ul.childNodes[0]);
	}

	// keep reference to logger. logger needs to work before it gets added to DOM.
	var logger = null;
	function getLogger()
	{
		if(!logger) {
			// create logger if null;
			logger = document.createElement( 'ul' );
			logger.id = 'logger';
		}
		
		// add logger to DOM
		if(document.body && !document.getElementById('logger'))
		{
			var loggerWrapper = document.createElement( 'div' );
			loggerWrapper.id = 'loggerWrapper';
			
			var logHeader = document.createElement( 'h1' );
			logHeader.innerText = 'Run logs:';
			loggerWrapper.appendChild( logHeader );
			
			loggerWrapper.appendChild( logger );
			document.body.appendChild( loggerWrapper );
		}
	
		return logger;		
	}	
	
	function objectToQuerystring( params ) {
		var query = '';
		
		for ( key in params ) {
			query += ( query ? '&' : '' ) + key + '=' + encodeURIComponent( params[key] );
		}
		
		return query;
	}
	
	function notifyServerAboutStepStart() {
		var params = {
			fail: window.TestSwarm.result.fail,
			error: window.TestSwarm.result.error,
			total: window.TestSwarm.result.total,
			beatRate: beatRate,
			action: 'runner',
			type: 'stepStart'
		};
	
		params = extendParams( params );
		params.resultsId = params.results_id;	// RunnerAction.php requires resultsId parameter rather than resultsId
		var url = baseUrl + 'api.php?' + objectToQuerystring( params );
				
		log('step Start ... ' + url);	
		var img = new Image();
		// TODO: use onLoad event
		//img.onLoad = function () { };
		img.src = url;
	}
	
	// Extend params by querystring parameters.
	function extendParams( params ) {
		var parts, paramItems = (url.split( '?' )[1] || '' ).split( '&' );

		for ( i = 0; i < paramItems.length; i += 1 ) {
			if ( paramItems[i] ) {
				parts = paramItems[i].split( '=' );
				if ( !params[ parts[0] ] ) {
					params[ parts[0] ] = parts[1];
				}
			}
		}
		
		return params;
	}
		
	function submit( params ) {
		log('Submitting runner results...');	

		notifyServerAboutStepStart();
		window.TestSwarm.heartbeat();	// we are still alive, trigger heartbeat so test execution won't time out	
		
		var form, i, input, key;

		if ( window.curHeartbeat ) {
			clearTimeout( window.curHeartbeat );
		}

		params = extendParams( params );

		if ( !params.action ) {
			params.action = 'saverun';
		}

		// Last chance to add something to the runner logs. Runner html gets serialized here.
		if ( doPost ) {
			log('Submitting results using postMessage...');
		} else {
			log('Submitting results by building and submitting html form...');			
		}
		
		log('Params=' + JSON.stringify(params));
		
		if ( !params.report_html ) {
			params.report_html = window.TestSwarm.serialize();
		}

		if ( DEBUG ) {
			window.alert( debugObj( params ) ) ;
		}

		if ( doPost ) {
			if ( !DEBUG ) {
				var query = objectToQuerystring( params );
				window.parent.postMessage( query, '*' );
				log('Message posted');
			}
		} else {
			form = document.createElement( 'form' );
			form.action = url;
			form.method = 'POST';

			for ( i in params ) {
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = i;
				input.value = params[i];
				form.appendChild( input );
			}

			if ( DEBUG ) {
				window.alert( url );

			} else {
				// Watch for the result submission timing out
				setTimeout(function () {
					submit( params );
				}, submitTimeout * 1000);

				log('Adding form to document');
				document.body.appendChild( form );
				log('Submit form');				
				form.submit();
				log('Form submitted!');				
			}
		}
	}

	function detectAndInstall() {
		var key;
		for ( key in testFrameworks ) {
			if ( testFrameworks[key].detect() ) {
				testFrameworks[key].install();
				return key;
			}
		}
		return false;
	}

	
	// Preserve other handlers
	onErrorFnPrev = window.onerror;

	// Cover uncaught exceptions
	// Returning true will surpress the default browser handler,
	// returning false will let it run.
	window.onerror = function ( error, filePath, linerNr ) {
		var ret = false, report;
		if ( onErrorFnPrev ) {
			ret = onErrorFnPrev( error, filePath, linerNr );
		}

		// Treat return value as window.onerror itself does,
		// Only do our handling if not surpressed.
		if ( ret !== true ) {
			report = document.createElement( 'div' );
			report.innerHTML = '<hr/><b>[TestSwarm] window.onerror:</b><br/>';
			report.appendChild( document.createTextNode( error ) );
			report.appendChild( document.createElement( 'br' ) );
			report.appendChild( document.createTextNode( 'in ' + filePath + ' on line ' + linerNr ) );
			document.body.appendChild( report );
			submit({ fail: 0, error: 1, total: 1 });

			return false;
		}

		return ret;
	};

	// Expose the TestSwarm API
	window.TestSwarm = {
		result: {
					fail: 0,
					error: 0,
					total: 0
				},
		submit: submit,
		heartbeat: function ( name ) {
			
			if ( window.curHeartbeat !== undefined ) {
				clearTimeout( window.curHeartbeat );
			}
		
			var msg = 'Heartbeating... ';
			if( !!name ) {
				if ( typeof name === "string" ) {
					name = msg + name;
				}
				
				if ( typeof name === "object" ) {
					name.message = msg + name.message;
				}
				
				log( name );
			}		

			window.curHeartbeat = setTimeout(function () {
				log('Heartbeat caused results submission...');
				submit({ status: 5, fail: window.TestSwarm.result.fail, total: window.TestSwarm.result.total, error: window.TestSwarm.result.error });
			}, beatRate * 1000);

		},
		serialize: function () {
			return trimSerialize();
		}
	};

	testFrameworks = {
		'Jasmine': {
			detect: function() {
				return typeof jasmine !== 'undefined' && typeof describe !== 'undefined' && typeof it !== 'undefined';
			},
			install: function() {
				log('installing Jasmine framework support');
				
				var jasmineTestSwarmResults = null;
				
				var testSwarmReporter = {
                    reportRunnerStarting: function (runner)
                    {
						// reset counters
						window.TestSwarm.result = jasmineTestSwarmResults = {
							fail: 0,
							error: 0,
							total: 0
						};
                        log('Jasmine reportRunnerStarting: ' + JSON.stringify(jasmineTestSwarmResults || {}));
                    },
                    reportRunnerResults: function (runner)
                    {
                        // testing finished
						log('Jasmine reportRunnerResults' + JSON.stringify(jasmineTestSwarmResults || {}));
						submit(jasmineTestSwarmResults);						
                    },
                    reportSuiteResults: function (suite)
                    {
                        log('Jasmine reportSuiteResults: ' + suite.description + ' ' + JSON.stringify(jasmineTestSwarmResults || {}));
						// not in use
                    },
                    reportSpecStarting: function (spec)
                    {
                        jasmineTestSwarmResults.total++;
						log('Jasmine reportSpecStarting: ' + spec.description + ' ' + JSON.stringify(jasmineTestSwarmResults || {}));
						
						// override beatRate with expected test duration.
						// jasmine it function cannot take additional parameter with options
						// duration can be set on the suite ( this.duration = 60 ) before it statement.
						beatRate = spec.suite.duration || defaultBeatRate;
						
						if(!!spec.suite.duration) {
							delete spec.suite.duration;
						}
						
						notifyServerAboutStepStart();
						window.TestSwarm.heartbeat();	// we are still alive, trigger heartbeat so test execution won't time out						
                    },
                    reportSpecResults: function (spec)
                    {
						if(spec.results().failedCount>0) {
							jasmineTestSwarmResults.fail++;
						}
						log('Jasmine reportSpecResults: ' + spec.description + ' ' + JSON.stringify(jasmineTestSwarmResults || {}));
                    },
                    log: function (str)
                    {
                        log('Jasmine says: ' + str + ' ' + JSON.stringify(jasmineTestSwarmResults || {}));
                    }
                };
				
				window.TestSwarm.serialize = function () {
					// take only the #wrapper and #html as a test result
					remove('content');	

					// move logger to after jasmine reporter
					var logger = document.getElementById('loggerWrapper');
					var reporter = document.getElementsByClassName('jasmine_reporter')[0];
					if(!!logger && !!reporter) {
						logger.parentElement.removeChild(logger);
						reporter.parentElement.appendChild(logger)
					}					
					
					return trimSerialize();
				};
				
				var jasmineEnv = jasmine.getEnv();
                jasmineEnv.addReporter(testSwarmReporter);
				
				log('Jasmine injected!');	
			}		
		},	
		
		// AngularJS
		// http://docs.angularjs.org/guide/dev_guide.e2e-testing
		'AngularJS': {
			detect: function() {
				log('Detecting AngularJS framework...');
				var isDetected = typeof angular !== 'undefined' && typeof describe !== 'undefined' && typeof it !== 'undefined';
				log('AngularJS framework detected: ' + isDetected);
				return isDetected;
			},
			install: function() {
				log('Installing AngularJS framework support...');
		
				window.TestSwarm.serialize = function () {
					
					// 'expand' nodes for each step
					var elements = document.getElementsByClassName('test-actions'); 
					if(elements) {
						for(var i = 0; i < elements.length; i++ ) {
							elements[i].style.display = 'block';
						}
					}

					// take only the #wrapper and #html as a test result
					remove('angularJsSwarm');
					remove('application');
					
					return trimSerialize();
				};
				
				// override xml, json and object scenario outputs so html is cleaner
				angular.scenario.output('xml', function(context, runner, model) { });
				angular.scenario.output('json', function(context, runner, model) { });
				angular.scenario.output('object', function(context, runner, model) { });
				
				angular.scenario.output('angularJsSwarm', function(context, runner, model) {
				
					var resetResults = function() {
						window.TestSwarm.result = angular.testSwarmResults = {
								fail: 0,
								error: 0,
								total: 0
							};
					};
					
					resetResults();

					model.on('SpecBegin', function(spec) {
						log('Spec Begin: ' + spec.name);
						angular.testSwarmResults.total++;
					});
					
					model.on('SpecEnd', function(spec) {
						log('Spec End: ' + spec.name);
						
						switch(spec.status)
						{
							case 'failure':
								angular.testSwarmResults.fail++;
								break;
							case 'error':
								angular.testSwarmResults.error++;
								break;
						}
					});

					model.on('RunnerEnd', function() {
						log('RunnerEnd');
						submit(angular.testSwarmResults);
						resetResults();
					});
					
					model.on('SpecError', function(spec, error) {					
						log('SpecError: ' + spec.name + ', ' + error.name);
					});

					model.on('StepBegin', function(spec, step) {					
						log('StepBegin: ' + spec.name + ', ' + step.name);
						
						// override beatRate with expected test duration. spec.duration is not implemented yet, value should come from the test.
						beatRate = spec.duration || defaultBeatRate;
						notifyServerAboutStepStart();						
						window.TestSwarm.heartbeat();	// we are still alive, trigger heartbeat so test execution won't time out
					});

					model.on('StepEnd', function(spec, step) {					
						log('StepEnd: ' + spec.name + ', ' + step.name);
					});

					model.on('StepFailure', function(spec, step, error) {					
						log('StepFailure: ' + spec.name + ', ' + step.name + ', ' + error.name);
					});

					model.on('StepError', function(spec, step, error) {					
						log('StepError: ' + spec.name + ', ' + step.name + ', ' + error.name);
					});
					
					model.on('RunnerError', function(error) {					
						log('RunnerError: ' + error.name);
					});

				});
				
				log('AngularJS injected!');	
			}		
		},	
		
		// QUnit (by jQuery)
		// http://docs.jquery.com/QUnit
		'QUnit': {
			detect: function () {
				return typeof QUnit !== 'undefined';
			},
			install: function () {
				log( 'Installing QUnit support...' );
				
				var moduleCount = 0, 
					logCount = 0;
		
				window.TestSwarm.result = {
					fail: 0,
					error: 0,
					total: 0
				};	
					
				QUnit.done = function ( results ) {
					window.TestSwarm.result.fail = results.failed;
					window.TestSwarm.result.total = results.total;
					submit(window.TestSwarm.result);
				};
				
				QUnit.testStart = function( nameObj ) {
					window.TestSwarm.result.total++;
					logCount = 0;
					var msg = 'QUnit: testStart ' + window.TestSwarm.result.total + ': ' + nameObj.name;
					var timeoutMargin = 10;
                    beatRate = !!nameObj && !!nameObj.config && !!nameObj.config.testTimeout ? (nameObj.config.testTimeout / 1000) + timeoutMargin : defaultBeatRate;
					notifyServerAboutStepStart();
					QUnit.heartbeat( {
						message: msg,
						cssClass: 'test'
					} );
				});
				
				QUnit.moduleStart = function( name ) {
					moduleCount++;
					var msg = 'QUnit: moduleStart ' + moduleCount + ': ' + name.name;
					QUnit.heartbeat( {
						message: msg,
						cssClass: 'group'
					} );
				};
				
				// result, actual, expected, message
				QUnit.log = function ( results ) {
					logCount++;
					var msg = 'QUnit: log ' + logCount + ': ' + results.message;
					QUnit.heartbeat( {
						message: msg,
						cssClass: 'run'
					} );					
				};				

				QUnit.heartbeat = window.TestSwarm.heartbeat;

				QUnit.heartbeat();

				window.TestSwarm.serialize = function () {
					var ol, i;

					// Show any collapsed results
					ol = document.getElementsByTagName( 'ol' );
					for ( i = 0; i < ol.length; i += 1 ) {
						ol[i].style.display = 'block';
					}

					return trimSerialize();
				};
			}
		},

		// UnitTestJS (Prototype, Scriptaculous)
		// https://github.com/tobie/unittest_js
		'UnitTestJS': {
			detect: function () {
				return typeof Test !== 'undefined' && Test && Test.Unit && Test.Unit.runners;
			},
			install: function () {
				/*jshint loopfunc:true */
				var	total_runners = Test.Unit.runners.length,
					cur_runners = 0,
					i;

				for ( i = 0; i < Test.Unit.runners.length; i += 1 ) {
					// Need to proxy the i variable into a local scope,
					// otherwise all the finish-functions created in this loop
					// will refer to the same i variable..
					(function ( i ) {
						var finish, results;

						finish = Test.Unit.runners[i].finish;
						Test.Unit.runners[i].finish = function () {
							finish.call( this );

							results = this.getResult();
							window.TestSwarm.result.total += results.assertions;
							window.TestSwarm.result.fail += results.failures;
							window.TestSwarm.result.error += results.errors;

							cur_runners += 1;
							if ( cur_runners === total_runners ) {
								submit(window.TestSwarm.result);
							}
						};
					}( i ) );
				}
			}
		},

		// JSSpec (MooTools)
		// http://jania.pe.kr/aw/moin.cgi/JSSpec
		// https://code.google.com/p/jsspec/
		'JSSpec': {
			detect: function () {
				return typeof JSSpec !== 'undefined' && JSSpec && JSSpec.Logger;
			},
			install: function () {
				var onRunnerEnd = JSSpec.Logger.prototype.onRunnerEnd;
				JSSpec.Logger.prototype.onRunnerEnd = function () {
					var ul, i;
					onRunnerEnd.call( this );

					// Show any collapsed results
					ul = document.getElementsByTagName( 'ul' );
					for ( i = 0; i < ul.length; i += 1 ) {
						ul[i].style.display = 'block';
					}

					submit(window.TestSwarm.result = {
						fail: JSSpec.runner.getTotalFailures(),
						error: JSSpec.runner.getTotalErrors(),
						total: JSSpec.runner.totalExamples
					});
				};

				window.TestSwarm.serialize = function () {
					var ul, i;
					// Show any collapsed results
					ul = document.getElementsByTagName( 'ul' );
					for ( i = 0; i < ul.length; i += 1 ) {
						ul[i].style.display = 'block';
					}

					return trimSerialize();
				};
			}
		},

		// JSUnit
		// http://www.jsunit.net/
		// Note: Injection file must be included before the frames
		// are document.write()d into the page.
		'JSUnit': {
			detect: function () {
				return typeof JsUnitTestManager !== 'undefined';
			},
			install: function () {
				var _done = JsUnitTestManager.prototype._done;
				JsUnitTestManager.prototype._done = function () {
					_done.call( this );

					submit(window.TestSwarm.result = {
						fail: this.failureCount,
						error: this.errorCount,
						total: this.totalCount
					});
				};

				window.TestSwarm.serialize = function () {
					return '<pre>' + this.log.join( '\n' ) + '</pre>';
				};
			}
		},

		// Selenium Core
		// http://seleniumhq.org/projects/core/
		'Selenium': {
			detect: function () {
				return typeof SeleniumTestResult !== 'undefined' && typeof LOG !== 'undefined';
			},
			install: function () {
				// Completely overwrite the postback
				SeleniumTestResult.prototype.post = function () {
					submit(window.TestSwarm.result = {
						fail: this.metrics.numCommandFailures,
						error: this.metrics.numCommandErrors,
						total: this.metrics.numCommandPasses + this.metrics.numCommandFailures + this.metrics.numCommandErrors
					});
				};

				window.TestSwarm.serialize = function () {
					var results = [], msg;
					while ( LOG.pendingMessages.length ) {
						msg = LOG.pendingMessages.shift();
						results.push( msg.type + ': ' + msg.msg );
					}

					return '<pre>' + results.join( '\n' ) + '</pre>';
				};
			}
		},

		// Dojo Objective Harness
		// http://docs.dojocampus.org/quickstart/doh
		'DOH': {
			detect: function () {
				return typeof doh !== 'undefined' && doh._report;
			},
			install: function () {
				var _report = doh._report;
				doh._report = function () {
					_report.apply( this, arguments );

					submit(window.TestSwarm.result = {
						fail: doh._failureCount,
						error: doh._errorCount,
						total: doh._testCount
					});
				};

				window.TestSwarm.serialize = function () {
					return '<pre>' + document.getElementById( 'logBody' ).innerHTML + '</pre>';
				};
			}
		},

		// Screw.Unit
		// https://github.com/nathansobo/screw-unit
		'Screw.Unit': {
			detect: function () {
				return typeof Screw !== 'undefined' && typeof jQuery !== 'undefined' && Screw && Screw.Unit;
			},
			install: function () {
				$(Screw).bind( 'after', function () {
					var	passed = $( '.passed' ).length,
						failed = $( '.failed' ).length;
					submit(window.TestSwarm.result = {
						fail: failed,
						error: 0,
						total: failed + passed
					});
				});

				$( Screw ).bind( 'loaded', function () {
					$( '.it' )
						.bind( 'passed', window.TestSwarm.heartbeat )
						.bind( 'failed', window.TestSwarm.heartbeat );
					window.TestSwarm.heartbeat();
				});

				window.TestSwarm.serialize = function () {
					return trimSerialize();
				};
			}
		},

		// Mocha
		// http://visionmedia.github.com/mocha/
		'Mocha': {
			detect: function () {
				return typeof Mocha !== 'undefined' && typeof mocha !== 'undefined';
			},
			install: function () {
				// Tab into the run method to install our hooks.
				// Use the mocha instance instead of the prototype, because
				// the mocha instance (HTMLReporter) also overloads .run.
				// This ensures our code runs after HTMLReporter is done.
				var run = mocha.run;
				mocha.run = function (fn) {
					var runner;

					// Sometimes (in IE9?) the 'end' event has already fired.
					// Registering on('end', fn) afterwards doesn't work.
					// So we use the .run(fn) callback instead, which is called
					// internally by Mocha right after the 'end' event.
					runner = run.call(this, function () {
						if (fn) {
							// Call the original callback given to .run(fn)
							fn.apply(this, arguments);
		}
						// `runner` can sometimes still be undefined here (at least in IE9).
						// Let the function return and pick up asynchronously
						// so the variable has been assigned.
						setTimeout(function () {
							submit({
								fail: runner.failures,
								total: runner.total,
								error: 0
							});
						}, 1);
					});

					runner.on('start', window.TestSwarm.heartbeat);
					runner.on('suite', window.TestSwarm.heartbeat);
					runner.on('test end', window.TestSwarm.heartbeat);
					runner.on('pass', window.TestSwarm.heartbeat);
					runner.on('fail', window.TestSwarm.heartbeat);

					return runner;
	};

				window.TestSwarm.serialize = function () {
					var i, len, els;
					els = document.getElementsByTagName('pre');
					// Expand all source code sections, because we submit a static
					// snapshot to TestSwarm, event handlers don't survive.
					for ( i = 0, len = els.length; i < len; i++ ) {
						els[i].style.display = 'inline-block';
					}
					return trimSerialize();
				};
			}
		}
	};

	detectAndInstall();

}() );
