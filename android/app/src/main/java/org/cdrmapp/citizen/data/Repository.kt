package org.cdrmapp.citizen.data
import com.google.gson.Gson
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import org.cdrmapp.citizen.BuildConfig
import java.util.concurrent.TimeUnit

class Repository(private val store: SecretStorage, baseUrl: String = BuildConfig.API_BASE_URL) {
 private val gson = Gson()
 private val client = OkHttpClient.Builder().connectTimeout(15, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).addInterceptor { chain ->
  val request = chain.request().newBuilder().header("Accept", "application/json")
  store.get("token")?.let { request.header("Authorization", "Bearer $it") }
  chain.proceed(request.build())
 }.build()
 val api: CdrmApi = Retrofit.Builder().baseUrl(baseUrl).client(client).addConverterFactory(GsonConverterFactory.create(gson)).build().create(CdrmApi::class.java)
 fun hasSession() = store.get("token") != null
 suspend fun login(email: String, password: String): Citizen {
  val result = api.login(mapOf("email" to email, "password" to password, "device_name" to "Android citizen")).data
  require(result.user.id > 0)
  store.put("token", result.token); store.put("user_id", result.user.id.toString())
  return result.user
 }
 fun pending(): Submission? = store.get("pending_" + store.get("user_id"))?.let { gson.fromJson(it, Submission::class.java) }
 fun persist(submission: Submission) = store.put("pending_" + store.get("user_id"), gson.toJson(submission))
 fun clearPending() = store.put("pending_" + store.get("user_id"), null)
 suspend fun send(submission: Submission): Incident {
  if (pending() != null) {
   val existing = try { api.receipt(submission.key).data } catch (e: retrofit2.HttpException) { if(e.code()==404) null else throw e }
   if(existing != null) { clearPending();return existing }
  }
  persist(submission) // The original payload/key survives timeouts and process death.
  val result = api.submit(submission).data
  clearPending() // Only clear after the server acknowledges durable receipt.
  return result
 }
 suspend fun logout() { try { store.get("push_token")?.let { runCatching { api.revokeDevice(it) } }; api.logout() } finally { store.put("token", null); store.put("user_id", null);store.put("push_token",null) } }
 fun clearSession() { store.put("token", null); store.put("user_id", null) }
}
