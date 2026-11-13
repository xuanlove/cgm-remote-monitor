'use strict';

const request = require('supertest');
const should = require('should');
const language = require('../lib/language')();

const PASSPHRASE = 'this is my long pass phrase';
// What a client actually sends: the server compares the SHA1 of the passphrase.
const API_SECRET = 'b723e97aa97846eb92d5264f084b2823f57c4aa1';

const ALICE = 'scoped-test-alice';
const BOB = 'scoped-test-bob';
const ALICE_ROLE = ALICE + '-role';

function profileFor (bgTargetLow, bgTargetHigh) {
  return {
    units: 'mg/dl'
    , dia: 3
    , carbs_hr: 30
    , carbs_req_units: 'g'
    , sens: [[0, 50]]
    , target_low: [[0, bgTargetLow]]
    , target_high: [[0, bgTargetHigh]]
    , basal: [[0, 1]]
    , timezone: 'UTC'
  };
}

describe('Profile API scoped access (api:profile:<name>:read/write)', function () {
  const self = this;

  this.timeout(30000);

  before(function (done) {
    delete process.env.API_SECRET;
    process.env.API_SECRET = PASSPHRASE;
    process.env.HOSTNAME = 'localhost';
    self.env = require('../lib/server/env')();
    self.env.settings.authDefaultRoles = 'denied';
    self.app = require('express')();
    self.app.enable('api');
    require('../lib/server/bootevent')(self.env, language).boot(function booted (ctx) {
      self.ctx = ctx;
      self.app.use('/api/v1', require('../lib/api/profile/')(self.app, ctx.wares, ctx));
      self.app.use('/api/v2/authorization', ctx.authorization.endpoints);
      done();
    });
  });

  function subjectsCollection () {
    return self.ctx.store.collection(self.env.authentication_collections_prefix + 'subjects');
  }

  function rolesCollection () {
    return self.ctx.store.collection(self.env.authentication_collections_prefix + 'roles');
  }

  function profileCollection () {
    return self.ctx.store.collection('profile');
  }

  let aliceToken;
  let bobToken;
  let profileRecord;

  before(async function setupFixtures () {
    // One shared profile document holding both users' store entries.
    await new Promise(function (resolve, reject) {
      self.ctx.profile.create([{
        store: {}
        , defaultProfile: ALICE
        , startDate: '1970-01-01T00:00:00.000Z'
      }], function created (err, created) {
        return err ? reject(err) : resolve(created);
      });
    });
    profileRecord = await profileCollection().findOne({ defaultProfile: ALICE });

    const store = {};
    store[ALICE] = profileFor(80, 130);
    store[BOB] = profileFor(90, 140);
    await profileCollection().updateOne({ _id: profileRecord._id }, { $set: { store: store } });

    // A role granting alice scoped read/write/create of her own store entry only,
    // plus a write-only entries permission (she must not read any data).
    await new Promise(function (resolve, reject) {
      self.ctx.authorization.storage.createRole({
        name: ALICE_ROLE
        , permissions: [
          'api:profile:' + ALICE + ':read'
          , 'api:profile:' + ALICE + ':write'
          , 'api:profile:' + ALICE + ':create'
          , 'api:entries:create'
        ]
      }, function created (err) {
        return err ? reject(err) : resolve();
      });
    });

    await new Promise(function (resolve, reject) {
      self.ctx.authorization.storage.createSubject({
        name: ALICE
        , roles: [ALICE_ROLE]
      }, function created (err) {
        return err ? reject(err) : resolve();
      });
    });

    await new Promise(function (resolve, reject) {
      self.ctx.authorization.storage.createSubject({
        name: BOB
        , roles: []
      }, function created (err) {
        return err ? reject(err) : resolve();
      });
    });

    const listed = await request(self.app)
      .get('/api/v2/authorization/subjects')
      .set('api-secret', API_SECRET)
      .expect(200);
    const alice = listed.body.find(s => s.name === ALICE);
    const bob = listed.body.find(s => s.name === BOB);
    should.exist(alice);
    should.exist(alice.accessToken);
    aliceToken = alice.accessToken;
    should.exist(bob);
    bobToken = bob.accessToken;
  });

  after(async function cleanup () {
    await profileCollection().deleteMany({ });
    await subjectsCollection().deleteMany({ name: ALICE });
    await subjectsCollection().deleteMany({ name: BOB });
    await rolesCollection().deleteMany({ name: ALICE_ROLE });
  });

  function wholeDocumentFromDb () {
    return profileCollection().findOne({ _id: profileRecord._id });
  }

  it('a scoped token reads only its own store entry', async function () {
    const read = await request(self.app)
      .get('/api/v1/profile/')
      .set('api-secret', aliceToken)
      .expect(200);

    read.body.should.be.an.Array().and.have.length(1);
    const doc = read.body[0];
    Object.keys(doc.store).should.deepEqual([ALICE]);
    doc.store[ALICE].target_low.should.deepEqual([[0, 80]]);
    doc.defaultProfile.should.equal(ALICE);
    doc.store.should.not.have.property(BOB);
  });

  it('an admin secret still reads the whole document', async function () {
    const read = await request(self.app)
      .get('/api/v1/profile/')
      .set('api-secret', API_SECRET)
      .expect(200);

    read.body[0].store.should.have.property(ALICE);
    read.body[0].store.should.have.property(BOB);
    read.body[0].defaultProfile.should.equal(ALICE);
  });

  it('a token without any profile grant is refused', async function () {
    const read = await request(self.app)
      .get('/api/v1/profile/')
      .set('api-secret', bobToken)
      .expect(401);
    read.body.status.should.equal(401);
  });

  it('a scoped PUT merges only its own store entry and preserves the others', async function () {
    const current = await wholeDocumentFromDb();

    const aliceStore = {};
    aliceStore[ALICE] = profileFor(85, 130);

    const updated = await request(self.app)
      .put('/api/v1/profile/')
      .set('api-secret', aliceToken)
      .send({
        _id: current._id.toString()
        , store: aliceStore
        , defaultProfile: BOB
        , startDate: '2030-01-01T00:00:00.000Z'
      })
      .expect(200);

    // The response shows only what alice may see.
    Object.keys(updated.body.store).should.deepEqual([ALICE]);
    updated.body.store[ALICE].target_low.should.deepEqual([[0, 85]]);
    updated.body.defaultProfile.should.equal(ALICE);

    // Everything alice does not own survived untouched in the database.
    const stored = await wholeDocumentFromDb();
    stored.store.should.have.property(BOB);
    stored.store[BOB].target_low.should.deepEqual([[0, 90]]);
    stored.defaultProfile.should.equal(ALICE);
    stored.startDate.should.equal('1970-01-01T00:00:00.000Z');
    stored.store[ALICE].target_low.should.deepEqual([[0, 85]]);
  });

  it('a scoped PUT refuses to write store entries outside its scope', async function () {
    const current = await wholeDocumentFromDb();

    const foreignStore = {};
    foreignStore[BOB] = profileFor(10, 20);
    const refused = await request(self.app)
      .put('/api/v1/profile/')
      .set('api-secret', aliceToken)
      .send({
        _id: current._id.toString()
        , store: foreignStore
      })
      .expect(403);

    refused.body.description.should.containEql('scope violation');

    const stored = await wholeDocumentFromDb();
    stored.store[BOB].target_high.should.deepEqual([[0, 140]]);
  });

  it('a scoped POST creates a new record containing only its own store entry', async function () {
    const newStore = {};
    newStore[ALICE] = profileFor(70, 160);
    const created = await request(self.app)
      .post('/api/v1/profile/')
      .set('api-secret', aliceToken)
      .send({ store: newStore })
      .expect(200);

    created.body.should.be.an.Array().and.have.length(1);
    Object.keys(created.body[0].store).should.deepEqual([ALICE]);
    created.body[0].defaultProfile.should.equal(ALICE);

    // Two records now: the shared original plus alice's treatment-plan record.
    (await profileCollection().countDocuments({ })).should.equal(2);

    // bob, who has no profile grants at all, is still refused.
    await request(self.app)
      .post('/api/v1/profile/')
      .set('api-secret', bobToken)
      .send({ store: newStore })
      .expect(401);

    // clean up so later tests see the original single-document state
    await profileCollection().deleteOne({ defaultProfile: ALICE, startDate: { $ne: '1970-01-01T00:00:00.000Z' } });
  });

  it('a scoped PUT refuses to create records for another user', async function () {
    const foreignStore = {};
    foreignStore[BOB] = profileFor(10, 20);
    const refused = await request(self.app)
      .post('/api/v1/profile/')
      .set('api-secret', aliceToken)
      .send({ store: foreignStore })
      .expect(403);

    refused.body.description.should.containEql('scope violation');
    (await profileCollection().countDocuments({ })).should.equal(1);
  });

  it('a scoped token cannot delete profile records', async function () {
    const current = await wholeDocumentFromDb();

    await request(self.app)
      .delete('/api/v1/profile/' + current._id.toString())
      .set('api-secret', aliceToken)
      .expect(401);

    (await profileCollection().countDocuments({ })).should.equal(1);
  });

  it('a scoped read never leaks other store entries through /profile/current', async function () {
    const read = await request(self.app)
      .get('/api/v1/profile/current')
      .set('api-secret', aliceToken)
      .expect(200);

    Object.keys(read.body.store).should.deepEqual([ALICE]);
    read.body.defaultProfile.should.equal(ALICE);
  });
});
