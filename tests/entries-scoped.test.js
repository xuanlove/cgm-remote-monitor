'use strict';

const request = require('supertest');
const should = require('should');
const language = require('../lib/language')();

const PASSPHRASE = 'this is my long pass phrase';
// What a client actually sends: the server compares the SHA1 of the passphrase.
const API_SECRET = 'b723e97aa97846eb92d5264f084b2823f57c4aa1';

const ALICE_DEVICE = 'scoped-entry-alice';
const BOB_DEVICE = 'scoped-entry-bob';
const ALICE = 'entries-scoped-alice';
const BOB = 'entries-scoped-bob';
const ALICE_ROLE = ALICE + '-role';

function entry (device, sgv, minutesAgo, now) {
  return {
    type: 'sgv'
    , sgv: sgv
    , device: device
    , date: now - minutesAgo * 60000
    , dateString: new Date(now - minutesAgo * 60000).toISOString()
  };
}

describe('Entries API scoped read (api:entries:<device>:read)', function () {
  const self = this;

  this.timeout(30000);

  before(function (done) {
    delete process.env.API_SECRET;
    process.env.API_SECRET = PASSPHRASE;
    process.env.HOSTNAME = 'localhost';
    self.env = require('../lib/server/env')();
    self.env.settings.authDefaultRoles = 'denied';
    self.env.settings.obscureDeviceProvenance = false;
    self.app = require('express')();
    self.app.enable('api');
    require('../lib/server/bootevent')(self.env, language).boot(function booted (ctx) {
      self.ctx = ctx;
      self.app.use('/api/v1', require('../lib/api/entries/')(self.app, ctx.wares, ctx, self.env));
      self.app.use('/api/v2/authorization', ctx.authorization.endpoints);
      done();
    });
  });

  function entriesCollection () {
    return self.ctx.store.collection('entries');
  }

  function subjectsCollection () {
    return self.ctx.store.collection(self.env.authentication_collections_prefix + 'subjects');
  }

  function rolesCollection () {
    return self.ctx.store.collection(self.env.authentication_collections_prefix + 'roles');
  }

  let aliceToken;
  let bobToken;
  let aliceEntryId;
  let bobEntryId;

  before(async function setupFixtures () {
    const now = Date.now();
    const result = await entriesCollection().insertMany([
      entry(ALICE_DEVICE, 100, 30, now)
      , entry(ALICE_DEVICE, 120, 20, now)
      , entry(BOB_DEVICE, 200, 10, now)
      , entry(BOB_DEVICE, 220, 5, now)
    ]);
    aliceEntryId = result.insertedIds[0].toString();
    bobEntryId = result.insertedIds[2].toString();

    // A role granting only scoped reads of alice's device (no full read, no
    // other collection permissions).
    await new Promise(function (resolve, reject) {
      self.ctx.authorization.storage.createRole({
        name: ALICE_ROLE
        , permissions: ['api:entries:' + ALICE_DEVICE + ':read']
      }, function created (err) {
        return err ? reject(err) : resolve();
      });
    });

    for (const [name, roles] of [[ALICE, [ALICE_ROLE]], [BOB, []]]) {
      await new Promise(function (resolve, reject) {
        self.ctx.authorization.storage.createSubject({ name, roles }, function created (err) {
          return err ? reject(err) : resolve();
        });
      });
    }

    const listed = await request(self.app)
      .get('/api/v2/authorization/subjects')
      .set('api-secret', API_SECRET)
      .expect(200);
    aliceToken = listed.body.find(s => s.name === ALICE).accessToken;
    bobToken = listed.body.find(s => s.name === BOB).accessToken;
    should.exist(aliceToken);
    should.exist(bobToken);
  });

  after(async function cleanup () {
    await entriesCollection().deleteMany({ device: { $in: [ALICE_DEVICE, BOB_DEVICE] } });
    await subjectsCollection().deleteMany({ name: { $in: [ALICE, BOB] } });
    await rolesCollection().deleteMany({ name: ALICE_ROLE });
  });

  function sgvs (body) {
    return body.map(e => e.sgv).sort((a, b) => a - b);
  }

  it('a scoped token reads only its own device', async function () {
    const res = await request(self.app)
      .get('/api/v1/entries.json')
      .query({ count: 10 })
      .set('api-secret', aliceToken)
      .expect(200);

    sgvs(res.body).should.deepEqual([100, 120]);
  });

  it('an admin secret still reads every device', async function () {
    const res = await request(self.app)
      .get('/api/v1/entries.json')
      .query({ count: 10 })
      .set('api-secret', API_SECRET)
      .expect(200);

    sgvs(res.body).should.deepEqual([100, 120, 200, 220]);
  });

  it('a find[device] pointing elsewhere returns nothing, not that device', async function () {
    const res = await request(self.app)
      .get('/api/v1/entries.json')
      .query({ count: 10, 'find[device]': BOB_DEVICE })
      .set('api-secret', aliceToken)
      .expect(200);

    res.body.should.deepEqual([]);
  });

  it('a find[device] inside the scope still narrows the result', async function () {
    const res = await request(self.app)
      .get('/api/v1/entries.json')
      .query({ count: 10, 'find[device]': ALICE_DEVICE })
      .set('api-secret', aliceToken)
      .expect(200);

    sgvs(res.body).should.deepEqual([100, 120]);
  });

  it('a scoped token cannot read another device entry by id', async function () {
    await request(self.app)
      .get('/api/v1/entries/' + bobEntryId)
      .set('api-secret', aliceToken)
      .expect(401);

    // Its own id is allowed through the scope check. (The v1 by-id route
    // answers an empty body in this minimal mount for admin callers too, so
    // only the status is asserted here.)
    const adminSameId = await request(self.app)
      .get('/api/v1/entries/' + aliceEntryId)
      .set('api-secret', API_SECRET)
      .expect(200);
    adminSameId.status.should.equal(200);

    await request(self.app)
      .get('/api/v1/entries/' + aliceEntryId)
      .set('api-secret', aliceToken)
      .expect(200);
  });

  it('/entries/current stays inside the scoped device', async function () {
    const res = await request(self.app)
      .get('/api/v1/entries/current.json')
      .set('api-secret', aliceToken)
      .expect(200);

    res.body.should.have.length(1);
    // Bob's 220 is the newest overall; alice's newest is 120.
    res.body[0].sgv.should.equal(120);
  });

  it('a token with no entries permission is still refused', async function () {
    await request(self.app)
      .get('/api/v1/entries.json')
      .set('api-secret', bobToken)
      .expect(401);
  });

  it('a scoped read does not grant writes', async function () {
    await request(self.app)
      .post('/api/v1/entries.json')
      .set('api-secret', aliceToken)
      .send([entry(ALICE_DEVICE, 333, 1, Date.now())])
      .expect(401);

    (await entriesCollection().countDocuments({ device: ALICE_DEVICE })).should.equal(2);
  });
});
