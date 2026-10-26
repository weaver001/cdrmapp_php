package org.cdrmapp.citizen
import org.cdrmapp.citizen.data.*
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.Assert.*
import org.junit.Test
import retrofit2.HttpException

class RepositoryTest {
 private class MemoryStore : SecretStorage {
  val entries = mutableMapOf<String, String>()
  override fun put(name: String, value: String?) { if (value == null) entries.remove(name) else entries[name] = value }
  override fun get(name: String) = entries[name]
 }
 private val submission = Submission(1, "SYNTHETIC test report", 14.5995, 120.9842, null, "manual", null, "d3894ee3-7204-45ae-bb36-83fe40dc28b4")
 @Test fun failedSubmissionRetainsExactPayloadAndKeyUntilDurableAcknowledgement() = runBlocking {
  val server = MockWebServer(); server.start()
  try {
   val store = MemoryStore().apply { put("user_id", "42"); put("token", "synthetic-token") }
   val repo = Repository(store, server.url("/api/v1/").toString())
   server.enqueue(MockResponse().setResponseCode(503).setBody("{\"error\":{\"message\":\"Unavailable\"}}"))
   try { repo.send(submission); fail("A failed request must never be acknowledged.") } catch (_: HttpException) { }
   assertEquals(submission, repo.pending())
   val first = server.takeRequest()
   assertEquals("Bearer synthetic-token", first.getHeader("Authorization"))
   server.enqueue(MockResponse().setResponseCode(404).setBody("{}"))
   server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":{"id":7,"public_reference":"synthetic-reference","description":"SYNTHETIC test report","workflow_status":"submitted","received_at":"2026-10-22T00:00:00Z","latitude":14.5995,"longitude":120.9842}}"""))
   val result = repo.send(repo.pending()!!)
   assertEquals(7L, result.id); assertNull(repo.pending())
   assertEquals("GET",server.takeRequest().method)
   assertEquals(first.body.readUtf8(), server.takeRequest().body.readUtf8())
  } finally { server.shutdown() }
 }
 @Test fun loginPersistsTokenAndUserOwnership() = runBlocking {
  val server = MockWebServer(); server.start()
  try {
   server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":{"user":{"id":42,"name":"Synthetic","email":"citizen@example.test","email_verified_at":null},"token":"synthetic-token"}}"""))
   val store = MemoryStore();val repo = Repository(store, server.url("/api/v1/").toString())
   assertEquals(42L, repo.login("citizen@example.test", "synthetic-password").id)
   assertEquals("synthetic-token", store.get("token")); assertEquals("42", store.get("user_id")); assertTrue(repo.hasSession())
  } finally { server.shutdown() }
 }
 @Test fun wrongLoginCredentialsUseOnlyTheRequestedMessage() = runBlocking {
  val server = MockWebServer(); server.start()
  try {
   val store = MemoryStore()
   val repo = Repository(store, server.url("/api/v1/").toString())
   for (status in listOf(401, 422)) {
    server.enqueue(MockResponse().setResponseCode(status).setBody("""{"error":{"message":"Invalid credentials or inactive account."}}"""))
    try { repo.login("wrong@example.test", "incorrect"); fail("Expected rejected credentials") } catch (e: InvalidCredentialsException) { assertEquals("Incorrect Username/Password", e.message) }
    assertFalse(repo.hasSession())
   }
   for (status in listOf(429, 500)) {
    server.enqueue(MockResponse().setResponseCode(status).setBody("{}"))
    try { repo.login("wrong@example.test", "incorrect"); fail("Expected HTTP failure") } catch (e: HttpException) { assertEquals(status, e.code()) }
   }
  } finally { server.shutdown() }
 }
 @Test fun freshLoginAttachesTokenToEveryFollowUpRequestAndDiagnosticsExcludeSecrets() = runBlocking {
  val server = MockWebServer(); server.start()
  try {
   server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":{"user":{"id":42,"name":"Synthetic","email":"citizen@example.test"},"token":"synthetic-secret-token"}}"""))
   server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":{"id":42,"name":"Synthetic","email":"citizen@example.test"}}"""))
   server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":[]}"""))
   server.enqueue(MockResponse().setResponseCode(401).setBody("{}"))
   val diagnostics = mutableListOf<String>()
   val repo = Repository(MemoryStore(), server.url("/api/v1/").toString(), diagnostics::add)
   repo.login("citizen@example.test", "synthetic-password")
   assertEquals(42L, repo.api.me().data.id)
   assertTrue(repo.api.categories().data.isEmpty())
   try { repo.api.incidents(1); fail("Expected unauthorized history request") } catch (e: HttpException) { assertEquals(401, e.code()) }
   assertNull(server.takeRequest().getHeader("Authorization"))
   repeat(3) { assertEquals("Bearer synthetic-secret-token", server.takeRequest().getHeader("Authorization")) }
   assertTrue(diagnostics.last().contains("/api/v1/incidents status=401 tokenSaved=true bearerSent=true"))
   for (line in diagnostics) {
    assertFalse(line.contains("synthetic-secret-token")); assertFalse(line.contains("synthetic-password")); assertFalse(line.contains("citizen@example.test")); assertFalse(line.contains("?page="))
   }
  } finally { server.shutdown() }
 }
 @Test fun unreadableSavedTokenIsReportedAsStorageFailureBeforeAuthenticatedRequests() = runBlocking {
  val server = MockWebServer(); server.start()
  try {
   server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":{"user":{"id":42,"name":"Synthetic","email":"citizen@example.test"},"token":"synthetic-token"}}"""))
   val store = object : SecretStorage {
    override fun put(name: String, value: String?) {}
    override fun get(name: String): String? = null
   }
   val repo = Repository(store, server.url("/api/v1/").toString())
   try { repo.login("citizen@example.test", "synthetic-password"); fail("Unreadable session must fail") } catch (_: SessionStorageException) {}
   assertFalse(repo.hasSession()); assertEquals(1, server.requestCount)
  } finally { server.shutdown() }
 }
 @Test fun reloadingHistoryReadsTheUpdatedServerStatus() = runBlocking {
  val server = MockWebServer(); server.start()
  try {
   for (status in listOf("need_action", "done")) server.enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody("""{"data":[{"id":7,"public_reference":"synthetic-reference","description":"SYNTHETIC test report","workflow_status":"$status","received_at":"2026-10-22T00:00:00Z","latitude":14.8139,"longitude":121.0453}],"meta":{"current_page":1,"last_page":1}}"""))
   val repo = Repository(MemoryStore().apply { put("token", "synthetic-token") }, server.url("/api/v1/").toString())
   assertEquals("need_action", repo.api.incidents(1).data.single().status)
   assertEquals("done", repo.api.incidents(1).data.single().status)
   repeat(2) { assertEquals("/api/v1/incidents?page=1", server.takeRequest().path) }
  } finally { server.shutdown() }
 }
}
