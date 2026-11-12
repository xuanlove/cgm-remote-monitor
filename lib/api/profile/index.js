'use strict';

var consts = require('../../constants');
var normalizeDeleteStatus = require('../shared/delete-status');
var sendQueryValidationError = require('../shared/query-error');
var objectIdValidation = require('../shared/objectid-validation');

function configure (app, wares, ctx) {
    var express = require('express'),
        api = express.Router( );

    // invoke common middleware
    api.use(wares.sendJSONStatus);
    // text body types get handled as raw buffer stream
    api.use(wares.rawParser);
    // json body types get handled as parsed json
    api.use(wares.jsonParser);
    // also support url-encoded content-type
    api.use(wares.urlencodedParser);
    // text body types get handled as raw buffer stream

    // Profile access: full `api:profile:read`/`update` grants keep whole-document
    // access; scoped tokens (`api:profile:<name>:read`/`:write`) pass through
    // with `req.profileScope` and the handlers below enforce the scope.
    api.use(ctx.authorization.profileAccessGate());

    function visibleNames (scope) {
        if (scope.readAll) return null;
        return scope.read.length > 0 ? scope.read : scope.write;
    }

    // Strip every store entry the token is not scoped to; a token without any
    // read grant sees an empty store rather than someone else's settings.
    function projectRecord (record, names) {
        if (!record || !record.store) return record;
        const projected = Object.assign({ }, record, { store: { } });
        names.forEach(function (name) {
            if (record.store[name] !== undefined) projected.store[name] = record.store[name];
        });
        projected.defaultProfile = names.indexOf(record.defaultProfile) > -1 ? record.defaultProfile : (names[0] || null);
        return projected;
    }

    function projectRecords (records, scope) {
        const names = visibleNames(scope);
        if (names === null) return records;
        return (records || []).map(function (record) {
            return projectRecord(record, names);
        });
    }

    // Scoped PUT: the request body may only touch store entries the token has
    // `api:profile:<name>:write` for. Everything else on the document - other
    // users' store entries, defaultProfile, startDate - is owned by the
    // instance and is carried over untouched from the current record.
    function mergeScopedProfile (data, scope, res) {
        const bodyStore = data.store && typeof data.store === 'object' ? data.store : null;
        if (!bodyStore) {
            return res.sendJSONStatus(res, consts.HTTP_BAD_REQUEST, 'Bad Request', 'store is required');
        }

        const foreign = Object.keys(bodyStore).filter(function (name) {
            return scope.write.indexOf(name) === -1;
        });
        if (foreign.length > 0) {
            return res.sendJSONStatus(res, 403, 'Forbidden',
                'Profile scope violation: not permitted to write store entries: ' + foreign.join(', '));
        }

        const writable = Object.keys(bodyStore).filter(function (name) {
            return scope.write.indexOf(name) > -1;
        });
        if (writable.length === 0) {
            return res.sendJSONStatus(res, consts.HTTP_BAD_REQUEST, 'Bad Request',
                'No store entries within this token\'s profile scope');
        }

        ctx.profile.list(function (err, records) {
            if (err) {
                console.log('Error reading profile for scoped save');
                console.log(err);
                return res.sendJSONStatus(res, consts.HTTP_INTERNAL_ERROR, 'Mongo Error', err);
            }
            const current = (records || []).find(function (record) {
                return String(record._id) === String(data._id);
            });
            if (!current) {
                return res.sendJSONStatus(res, 404, 'Not Found', 'Profile record not found');
            }

            const merged = Object.assign({ }, current, { store: Object.assign({ }, current.store) });
            writable.forEach(function (name) {
                merged.store[name] = bodyStore[name];
            });
            ctx.purifier.purifyObject(merged);

            ctx.profile.save(merged, function (err, saved) {
                if (err) {
                    res.sendJSONStatus(res, consts.HTTP_INTERNAL_ERROR, 'Mongo Error', err);
                    console.log('Error saving profile');
                    console.log(err);
                } else {
                    res.json(projectRecords([saved], scope)[0]);
                    console.log('Profile saved (scoped:', scope.write.join(', ') + ')');
                }
            });
        }, 1000);
    }


   /**
   * @function query_models
   * Perform the standard query logic, translating API parameters into mongo
   * db queries in a fairly regimented manner.
   * This middleware executes the query, returning the results as JSON
   */
    function query_models (req, res, next) {
        var query = req.query;

        // If "?count=" is present, use that number to decide how many to return.
        if (!query.count) {
            query.count = consts.PROFILES_DEFAULT_COUNT;
        }

        // perform the query
        ctx.profile.list_query(query, function payload(err, profiles) {
            // `err` was ignored here entirely, so a failed read answered 200
            // with an undefined body. A refused filter now says which operator
            // was refused; every other failure keeps the shape the profile
            // endpoints already use for an unreadable collection.
            if (err) {
                if (sendQueryValidationError(res, err)) return;
                return res.status(consts.HTTP_INTERNAL_ERROR)
                    .json({status: consts.HTTP_INTERNAL_ERROR, message: 'Unable to read profiles'});
            }
            return res.json(projectRecords(profiles, req.profileScope));
        });
    }

    // List profiles available
    api.get('/profiles/', query_models);

    // List profiles available
    api.get('/profile/', function(req, res) {
        const limit = req.query && req.query.count ? Number(req.query.count) : consts.PROFILES_DEFAULT_COUNT;
        ctx.profile.list(function (err, attribute) {
            return res.json(projectRecords(attribute, req.profileScope));
        }, limit);
    });

    // List current active record (in current state LAST record is current active)
    api.get('/profile/current', function(req, res) {
        ctx.profile.last( function(err, records) {
            // Runs inside a promise handler: reading a null `records` here would
            // end the process, so a failed read answers an error instead.
            if (err) {
                if (sendQueryValidationError(res, err)) return;
                console.error('Error reading the current profile', err);
                return res.sendJSONStatus(res, consts.HTTP_INTERNAL_ERROR, 'Internal Server Error');
            }
            const current = records && records.length > 0 ? records[0] : null;
            return res.json(projectRecords([current], req.profileScope)[0]);
        });
    });

    function config_authed (app, api, wares, ctx) {
        function parseKeepCount (req) {
            var rawKeep = req.query && req.query.keep !== undefined ? req.query.keep : '100';
            var keep = Number(rawKeep);

            if (!Number.isSafeInteger(keep) || keep < 10 || keep > 10000) {
                return null;
            }

            return keep;
        }

        // create new record(s)
        // Supports both single object and array inputs (NightscoutKit sends arrays)
        api.post('/profile/', ctx.authorization.isPermitted('api:profile:create'), function(req, res) {
            var data = req.body;

            // Normalize to array (match treatments pattern)
            if (!Array.isArray(data)) {
                data = [data];
            }

            // Validate _id fields before storage (return 400 on invalid)
            var invalid = objectIdValidation.findInvalidId(data);
            if (invalid) {
                return res.sendJSONStatus(res, consts.HTTP_BAD_REQUEST,
                    'Invalid _id format', 'Must be 24-character hex string or omit for auto-generation. Got: ' + String(invalid.id));
            }

            // Purify each profile
            for (var i = 0; i < data.length; i++) {
                ctx.purifier.purifyObject(data[i]);
            }

            ctx.profile.create(data, function (err, created) {
                if (err) {
                    res.sendJSONStatus(res, consts.HTTP_INTERNAL_ERROR, 'Mongo Error', err);
                    console.log('Error creating profile');
                    console.log(err);
                } else {
                    res.json(created);
                    console.log('Profile(s) created', created.length);
                }
            });
        });

        // update record
        // Scoped tokens (`api:profile:<name>:write`) are merged into their own
        // store entries only; full-grant tokens update the whole document.
        api.put('/profile/', function(req, res) {
            var data = req.body;

            // Validate _id if provided (required for PUT, must be valid format)
            if (!objectIdValidation.isValidObjectId(data._id)) {
                return res.sendJSONStatus(res, consts.HTTP_BAD_REQUEST,
                    'Invalid _id format', 'Must be 24-character hex string. Got: ' + String(data._id));
            }

            if (!req.profileScope.writeAll) {
                return mergeScopedProfile(data, req.profileScope, res);
            }

            ctx.purifier.purifyObject(data);

            ctx.profile.save(data, function (err, created) {
                if (err) {
                    res.sendJSONStatus(res, consts.HTTP_INTERNAL_ERROR, 'Mongo Error', err);
                    console.log('Error saving profile');
                    console.log(err);
                } else {
                    res.json(created);
                    console.log('Profile saved', created);
                }

            });
        });

        api.delete('/profile/', ctx.authorization.isPermitted('api:profile:delete'), function(req, res) {
            var keepCount = parseKeepCount(req);

            if (keepCount === null) {
                return res.sendJSONStatus(res, consts.HTTP_BAD_REQUEST,
                    'Invalid keep count', 'Must be a whole number between 10 and 10000.');
            }

            ctx.profile.prune(keepCount, function (err, stat) {
                if (err) {
                    res.sendJSONStatus(res, consts.HTTP_INTERNAL_ERROR, 'Mongo Error', err);
                    console.log('Error pruning profiles');
                    console.log(err);
                } else {
                    res.json(normalizeDeleteStatus(stat));
                }
            });
        });

        api.delete('/profile/:_id', ctx.authorization.isPermitted('api:profile:delete'), function(req, res) {
            // Validate _id parameter
            if (!objectIdValidation.isValidObjectId(req.params._id)) {
                return res.sendJSONStatus(res, consts.HTTP_BAD_REQUEST,
                    'Invalid _id format', 'Must be 24-character hex string. Got: ' + String(req.params._id));
            }

          ctx.profile.remove(req.params._id, function ( ) {
            res.json({ });
          });
        });
    }

    if (app.enabled('api')) {
        config_authed(app, api, wares, ctx);
    }

    return api;
}

module.exports = configure;
