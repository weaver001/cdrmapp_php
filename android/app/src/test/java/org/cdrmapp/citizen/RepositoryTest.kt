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
}
