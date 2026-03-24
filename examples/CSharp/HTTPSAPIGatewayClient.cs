using System;
using System.Collections.Generic;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;
using HTTPClient = System.Net.Http.HttpClient;
using HTTPMethod = System.Net.Http.HttpMethod;
using HTTPRequestMessage = System.Net.Http.HttpRequestMessage;
using HTTPResponseMessage = System.Net.Http.HttpResponseMessage;
using HTTPStringContent = System.Net.Http.StringContent;

namespace BrennerDesktopSample;

public sealed class HTTPSAPIGatewayClientOptions {
	public string APIPath { get; init; } = "api.php";

	public TimeSpan Timeout { get; init; } = TimeSpan.FromSeconds(30);

	public bool RetryAuthenticatedConsumesOnceOnTransientFailure { get; init; } = true;

	public bool IncludeXRequestIDHeader { get; init; } = true;
}

public sealed class HTTPSAPIGatewayClient : IDisposable {
	private static readonly JsonSerializerOptions JSON_OPTIONS = new(JsonSerializerDefaults.Web) {
		PropertyNameCaseInsensitive = true,
		DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
	};

	private readonly HTTPClient _httpClient;
	private readonly bool _ownsHTTPClient;
	private readonly HTTPSAPIGatewayClientOptions _options;
	private readonly object _guidLock = new();
	private APIGUIDStateDTO? _currentGUIDState;

	public HTTPSAPIGatewayClient(Uri baseAddress)
		: this(baseAddress, new HTTPSAPIGatewayClientOptions()) {
	}

	public HTTPSAPIGatewayClient(Uri baseAddress, HTTPSAPIGatewayClientOptions options) {
		if (baseAddress is null) {
			throw new ArgumentNullException(nameof(baseAddress));
		}

		if (options is null) {
			throw new ArgumentNullException(nameof(options));
		}

		if (baseAddress.Scheme != Uri.UriSchemeHttps) {
			throw new ArgumentException("Base address must use HTTPS.", nameof(baseAddress));
		}

		_httpClient = new HTTPClient {
			BaseAddress = baseAddress,
			Timeout = options.Timeout,
		};
		_ownsHTTPClient = true;
		_options = options;
	}

	public HTTPSAPIGatewayClient(HTTPClient httpClient, HTTPSAPIGatewayClientOptions? options = null) {
		_httpClient = httpClient ?? throw new ArgumentNullException(nameof(httpClient));
		if (_httpClient.BaseAddress is Uri baseAddress && baseAddress.Scheme != Uri.UriSchemeHttps) {
			throw new ArgumentException("HTTPClient.BaseAddress must use HTTPS.", nameof(httpClient));
		}

		_options = options ?? new HTTPSAPIGatewayClientOptions();
		_ownsHTTPClient = false;
	}

	public APIGUIDStateDTO? CurrentGUIDState {
		get {
			lock (_guidLock) {
				return _currentGUIDState?.Clone();
			}
		}
	}

	public void ClearGUIDState() {
		lock (_guidLock) {
			_currentGUIDState = null;
		}
	}

	public Task<SYSTEMStatusDataDTO> GetSYSTEMSTATUSAsync(CancellationToken cancellationToken = default) {
		return SendActionAsync<SYSTEMStatusDataDTO>(
			"SYSTEM.STATUS",
			parameters: null,
			requiresGUID: false,
			consumesGUID: false,
			cancellationToken
		);
	}

	public async Task<AUTHOpenLinkDataDTO> OpenLINKAsync(
		string clientID,
		string clientSecret,
		CancellationToken cancellationToken = default) {
		var data = await SendActionAsync<AUTHOpenLinkDataDTO>(
			"AUTH.OPEN_LINK",
			new AUTHOpenLinkParamsDTO {
				ClientID = clientID,
				ClientSecret = clientSecret,
			},
			requiresGUID: false,
			consumesGUID: false,
			cancellationToken
		);

		if (CurrentGUIDState is null) {
			throw new InvalidOperationException("Gateway did not return GUID state after AUTH.OPEN_LINK.");
		}

		return data;
	}

	public Task<LINKEchoDataDTO> EchoLINKAsync(string nonce, CancellationToken cancellationToken = default) {
		return SendActionAsync<LINKEchoDataDTO>(
			"LINK.ECHO",
			new LINKEchoParamsDTO {
				Nonce = nonce,
			},
			requiresGUID: true,
			consumesGUID: true,
			cancellationToken
		);
	}

	public Task<LINKInfoDataDTO> GetLINKINFOAsync(CancellationToken cancellationToken = default) {
		return SendActionAsync<LINKInfoDataDTO>(
			"LINK.INFO",
			parameters: null,
			requiresGUID: true,
			consumesGUID: false,
			cancellationToken
		);
	}

	public Task<LINKRequireTagDataDTO> RequireLINKTagAsync(string tag, CancellationToken cancellationToken = default) {
		return SendActionAsync<LINKRequireTagDataDTO>(
			"LINK.REQUIRE_TAG",
			new {
				tag
			},
			requiresGUID: true,
			consumesGUID: false,
			cancellationToken
		);
	}

	public Task<DBProfilesDataDTO> GetDBProfilesAsync(CancellationToken cancellationToken = default) {
		return SendActionAsync<DBProfilesDataDTO>(
			"DB.PROFILES",
			new { },
			requiresGUID: true,
			consumesGUID: false,
			cancellationToken
		);
	}

	public Task<DBTablesDataDTO> GetDBTablesAsync(
		string? databaseProfile = null,
		string? schema = null,
		CancellationToken cancellationToken = default) {
		var parameters = new Dictionary<string, object?>();

		if (!string.IsNullOrWhiteSpace(databaseProfile)) {
			parameters["database"] = databaseProfile;
		}

		if (!string.IsNullOrWhiteSpace(schema)) {
			parameters["schema"] = schema;
		}

		return SendActionAsync<DBTablesDataDTO>(
			"DB.TABLES",
			parameters,
			requiresGUID: true,
			consumesGUID: false,
			cancellationToken
		);
	}

	public Task<DBColumnsDataDTO> GetDBColumnsAsync(
		string table,
		string? databaseProfile = null,
		string? schema = null,
		CancellationToken cancellationToken = default) {
		if (string.IsNullOrWhiteSpace(table)) {
			throw new ArgumentException("Table is required.", nameof(table));
		}

		var parameters = new Dictionary<string, object?> {
			["table"] = table,
		};

		if (!string.IsNullOrWhiteSpace(databaseProfile)) {
			parameters["database"] = databaseProfile;
		}

		if (!string.IsNullOrWhiteSpace(schema)) {
			parameters["schema"] = schema;
		}

		return SendActionAsync<DBColumnsDataDTO>(
			"DB.COLUMNS",
			parameters,
			requiresGUID: true,
			consumesGUID: false,
			cancellationToken
		);
	}

	public Task<DBReadDataDTO> ReadDBAsync(
		string sql,
		string? databaseProfile = null,
		IReadOnlyDictionary<string, object?>? bindings = null,
		int maxRows = 500,
		CancellationToken cancellationToken = default) {
		if (string.IsNullOrWhiteSpace(sql)) {
			throw new ArgumentException("SQL is required.", nameof(sql));
		}

		var parameters = new Dictionary<string, object?> {
			["sql"] = sql,
			["bindings"] = bindings ?? new Dictionary<string, object?>(),
			["max_rows"] = maxRows,
		};

		if (!string.IsNullOrWhiteSpace(databaseProfile)) {
			parameters["database"] = databaseProfile;
		}

		return SendActionAsync<DBReadDataDTO>(
			"DB.READ",
			parameters,
			requiresGUID: true,
			consumesGUID: false,
			cancellationToken
		);
	}

	public Task<DBWriteDataDTO> WriteDBAsync(
		string sql,
		string? databaseProfile = null,
		IReadOnlyDictionary<string, object?>? bindings = null,
		CancellationToken cancellationToken = default) {
		if (string.IsNullOrWhiteSpace(sql)) {
			throw new ArgumentException("SQL is required.", nameof(sql));
		}

		var parameters = new Dictionary<string, object?> {
			["sql"] = sql,
			["bindings"] = bindings ?? new Dictionary<string, object?>(),
		};

		if (!string.IsNullOrWhiteSpace(databaseProfile)) {
			parameters["database"] = databaseProfile;
		}

		return SendActionAsync<DBWriteDataDTO>(
			"DB.WRITE",
			parameters,
			requiresGUID: true,
			consumesGUID: true,
			cancellationToken
		);
	}

	public Task<DBExecuteDataDTO> ExecuteDBAsync(
		string sql,
		string? databaseProfile = null,
		IReadOnlyDictionary<string, object?>? bindings = null,
		int maxRows = 500,
		bool allRowsets = true,
		CancellationToken cancellationToken = default) {
		if (string.IsNullOrWhiteSpace(sql)) {
			throw new ArgumentException("SQL is required.", nameof(sql));
		}

		var parameters = new Dictionary<string, object?> {
			["sql"] = sql,
			["bindings"] = bindings ?? new Dictionary<string, object?>(),
			["max_rows"] = maxRows,
			["all_rowsets"] = allRowsets,
		};

		if (!string.IsNullOrWhiteSpace(databaseProfile)) {
			parameters["database"] = databaseProfile;
		}

		return SendActionAsync<DBExecuteDataDTO>(
			"DB.EXECUTE",
			parameters,
			requiresGUID: true,
			consumesGUID: true,
			cancellationToken
		);
	}

	public Task<TData> SendCustomActionAsync<TData>(
		string action,
		object? parameters = null,
		bool requiresGUID = true,
		bool consumesGUID = false,
		CancellationToken cancellationToken = default) {
		if (string.IsNullOrWhiteSpace(action)) {
			throw new ArgumentException("Action is required.", nameof(action));
		}

		return SendActionAsync<TData>(action, parameters, requiresGUID, consumesGUID, cancellationToken);
	}

	private async Task<TData> SendActionAsync<TData>(
		string action,
		object? parameters,
		bool requiresGUID,
		bool consumesGUID,
		CancellationToken cancellationToken) {
		var guidSnapshot = requiresGUID
			? GetCurrentGUIDOrThrow()
			: null;

		var requestEnvelope = new APIRequestEnvelopeDTO {
			Action = action,
			Params = parameters ?? new { },
			GUID = guidSnapshot,
		};

		var shouldRetry =
			requiresGUID
			&& consumesGUID
			&& _options.RetryAuthenticatedConsumesOnceOnTransientFailure;

		try {
			return await SendEnvelopeCoreAsync<TData>(requestEnvelope, cancellationToken);
		} catch (Exception exception) when (shouldRetry && IsTransientFailure(exception, cancellationToken)) {
			return await SendEnvelopeCoreAsync<TData>(requestEnvelope, cancellationToken);
		}
	}

	private async Task<TData> SendEnvelopeCoreAsync<TData>(
		APIRequestEnvelopeDTO requestEnvelope,
		CancellationToken cancellationToken) {
		using var request = new HTTPRequestMessage(HTTPMethod.Post, _options.APIPath);

		if (_options.IncludeXRequestIDHeader) {
			request.Headers.Add("X-Request-ID", Guid.NewGuid().ToString("N"));
		}

		request.Content = new HTTPStringContent(
			JsonSerializer.Serialize(requestEnvelope, JSON_OPTIONS),
			Encoding.UTF8,
			"application/json"
		);

		using HTTPResponseMessage response = await _httpClient.SendAsync(request, cancellationToken);
		var body = await response.Content.ReadAsStringAsync(cancellationToken);
		var apiResponse = DeserializeEnvelopeOrThrow<TData>(response, body, requestEnvelope.Action);

		var guidState = ExtractGUIDState(apiResponse);
		if (guidState is not null) {
			SetCurrentGUIDState(guidState);
		}

		if (!response.IsSuccessStatusCode || !apiResponse.OK) {
			throw BuildGatewayException(response, apiResponse, body);
		}

		return apiResponse.Data!;
	}

	private static APIResponseEnvelopeDTO<TData> DeserializeEnvelopeOrThrow<TData>(
		HTTPResponseMessage response,
		string responseBody,
		string action) {
		try {
			var envelope = JsonSerializer.Deserialize<APIResponseEnvelopeDTO<TData>>(responseBody, JSON_OPTIONS);
			if (envelope is null) {
				throw new APIGatewayException(
					httpStatusCode: (int) response.StatusCode,
					errorCode: "RESPONSE_DESERIALIZATION_FAILED",
					message: "API response body could not be deserialized.",
					requestID: null,
					action: action,
					errorDetails: null,
					rawResponseBody: Truncate(responseBody, 1200)
				);
			}

			return envelope;
		} catch (JsonException exception) {
			throw new APIGatewayException(
				httpStatusCode: (int) response.StatusCode,
				errorCode: "RESPONSE_JSON_INVALID",
				message: "API response body is not valid JSON.",
				requestID: null,
				action: action,
				errorDetails: null,
				rawResponseBody: Truncate(responseBody, 1200),
				innerException: exception
			);
		}
	}

	private static APIGatewayException BuildGatewayException<TData>(
		HTTPResponseMessage response,
		APIResponseEnvelopeDTO<TData> envelope,
		string responseBody) {
		var envelopeStatusCode = envelope.Error?.HTTPStatus;
		var effectiveStatusCode = envelopeStatusCode > 0
			? envelopeStatusCode.Value
			: (int) response.StatusCode;

		return new APIGatewayException(
			httpStatusCode: effectiveStatusCode,
			errorCode: envelope.Error?.Code ?? "HTTP_ERROR",
			message: envelope.Error?.Message ?? response.ReasonPhrase ?? "Unknown API error.",
			requestID: envelope.RequestID,
			action: envelope.Action,
			errorDetails: envelope.Error?.Details,
			rawResponseBody: Truncate(responseBody, 1200)
		);
	}

	private static APIGUIDStateDTO? ExtractGUIDState<TData>(APIResponseEnvelopeDTO<TData> apiResponse) {
		if (string.IsNullOrWhiteSpace(apiResponse.GUID)) {
			return null;
		}

		if (apiResponse.GUIDSequence is null || string.IsNullOrWhiteSpace(apiResponse.GUIDExpiresAt)) {
			throw new InvalidOperationException("API response returned an incomplete GUID state.");
		}

		return new APIGUIDStateDTO {
			GUID = apiResponse.GUID,
			GUIDSequence = apiResponse.GUIDSequence.Value,
			GUIDExpiresAt = apiResponse.GUIDExpiresAt,
		};
	}

	private string GetCurrentGUIDOrThrow() {
		lock (_guidLock) {
			return _currentGUIDState?.GUID
				?? throw new InvalidOperationException("GUID state is missing. Call OpenLINKAsync before authenticated actions.");
		}
	}

	private void SetCurrentGUIDState(APIGUIDStateDTO guidState) {
		lock (_guidLock) {
			_currentGUIDState = guidState;
		}
	}

	private static bool IsTransientFailure(Exception exception, CancellationToken cancellationToken) {
		if (exception is TaskCanceledException && !cancellationToken.IsCancellationRequested) {
			return true;
		}

		return exception is System.Net.Http.HttpRequestException;
	}

	private static string Truncate(string text, int maxLength) {
		if (text.Length <= maxLength) {
			return text;
		}

		return text.Substring(0, maxLength) + "...";
	}

	public void Dispose() {
		if (_ownsHTTPClient) {
			_httpClient.Dispose();
		}
	}
}

public sealed class APIGatewayException : Exception {
	public APIGatewayException(
		int httpStatusCode,
		string errorCode,
		string message,
		string? requestID,
		string? action,
		JsonElement? errorDetails,
		string? rawResponseBody,
		Exception? innerException = null)
		: base($"{errorCode}: {message} (http={httpStatusCode}, request_id={requestID}, action={action})", innerException) {
		HTTPStatusCode = httpStatusCode;
		ErrorCode = errorCode;
		RequestID = requestID;
		Action = action;
		ErrorDetails = errorDetails;
		RawResponseBody = rawResponseBody;
	}

	public int HTTPStatusCode { get; }

	public string ErrorCode { get; }

	public string? RequestID { get; }

	public string? Action { get; }

	public JsonElement? ErrorDetails { get; }

	public string? RawResponseBody { get; }
}

public sealed class APIRequestEnvelopeDTO {
	[JsonPropertyName("action")]
	public required string Action { get; init; }

	[JsonPropertyName("params")]
	public object? Params { get; init; }

	[JsonPropertyName("guid")]
	public string? GUID { get; init; }
}

public sealed class APIResponseEnvelopeDTO<TData> {
	[JsonPropertyName("ok")]
	public bool OK { get; init; }

	[JsonPropertyName("request_id")]
	public string? RequestID { get; init; }

	[JsonPropertyName("timestamp")]
	public string? Timestamp { get; init; }

	[JsonPropertyName("action")]
	public string? Action { get; init; }

	[JsonPropertyName("data")]
	public TData? Data { get; init; }

	[JsonPropertyName("meta")]
	public JsonElement? Meta { get; init; }

	[JsonPropertyName("client")]
	public APIClientDTO? Client { get; init; }

	[JsonPropertyName("guid")]
	public string? GUID { get; init; }

	[JsonPropertyName("guid_sequence")]
	public int? GUIDSequence { get; init; }

	[JsonPropertyName("guid_expires_at")]
	public string? GUIDExpiresAt { get; init; }

	[JsonPropertyName("error")]
	public APIErrorDTO? Error { get; init; }
}

public sealed class APIClientDTO {
	[JsonPropertyName("client_id")]
	public string? ClientID { get; init; }

	[JsonPropertyName("scopes")]
	public List<string>? Scopes { get; init; }
}

public sealed class APIErrorDTO {
	[JsonPropertyName("http_status")]
	public int? HTTPStatus { get; init; }

	[JsonPropertyName("code")]
	public string? Code { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }

	[JsonPropertyName("details")]
	public JsonElement? Details { get; init; }
}

public sealed class APIGUIDStateDTO {
	public required string GUID { get; init; }

	public int GUIDSequence { get; init; }

	public required string GUIDExpiresAt { get; init; }

	public APIGUIDStateDTO Clone() {
		return new APIGUIDStateDTO {
			GUID = GUID,
			GUIDSequence = GUIDSequence,
			GUIDExpiresAt = GUIDExpiresAt,
		};
	}
}

public sealed class AUTHOpenLinkParamsDTO {
	[JsonPropertyName("client_id")]
	public required string ClientID { get; init; }

	[JsonPropertyName("client_secret")]
	public required string ClientSecret { get; init; }
}

public sealed class AUTHOpenLinkDataDTO {
	[JsonPropertyName("opened")]
	public bool Opened { get; init; }

	[JsonPropertyName("display_name")]
	public string? DisplayName { get; init; }
}

public sealed class LINKEchoParamsDTO {
	[JsonPropertyName("nonce")]
	public required string Nonce { get; init; }
}

public sealed class LINKEchoDataDTO {
	[JsonPropertyName("echo")]
	public JsonElement? Echo { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }
}

public sealed class LINKInfoDataDTO {
	[JsonPropertyName("authenticated")]
	public bool Authenticated { get; init; }

	[JsonPropertyName("mode")]
	public string? Mode { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }
}

public sealed class LINKRequireTagDataDTO {
	[JsonPropertyName("accepted")]
	public bool Accepted { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }
}

public sealed class SYSTEMStatusDataDTO {
	[JsonPropertyName("online")]
	public bool Online { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }

	[JsonPropertyName("transport")]
	public string? Transport { get; init; }

	[JsonPropertyName("authentication")]
	public string? Authentication { get; init; }
}

public sealed class DBProfilesDataDTO {
	[JsonPropertyName("profiles")]
	public List<string>? Profiles { get; init; }

	[JsonPropertyName("count")]
	public int Count { get; init; }
}

public sealed class DBTablesDataDTO {
	[JsonPropertyName("database_profile")]
	public string? DatabaseProfile { get; init; }

	[JsonPropertyName("schema")]
	public string? Schema { get; init; }

	[JsonPropertyName("tables")]
	public List<string>? Tables { get; init; }

	[JsonPropertyName("count")]
	public int Count { get; init; }
}

public sealed class DBColumnsDataDTO {
	[JsonPropertyName("database_profile")]
	public string? DatabaseProfile { get; init; }

	[JsonPropertyName("schema")]
	public string? Schema { get; init; }

	[JsonPropertyName("table")]
	public string? Table { get; init; }

	[JsonPropertyName("columns")]
	public JsonElement? Columns { get; init; }

	[JsonPropertyName("count")]
	public int Count { get; init; }
}

public sealed class DBReadDataDTO {
	[JsonPropertyName("rows")]
	public JsonElement? Rows { get; init; }

	[JsonPropertyName("row_count")]
	public int RowCount { get; init; }

	[JsonPropertyName("truncated")]
	public bool Truncated { get; init; }

	[JsonPropertyName("max_rows")]
	public int MaxRows { get; init; }
}

public sealed class DBWriteDataDTO {
	[JsonPropertyName("affected_rows")]
	public int AffectedRows { get; init; }

	[JsonPropertyName("last_insert_id")]
	public string? LastInsertID { get; init; }
}

public sealed class DBExecuteResultSetDTO {
	[JsonPropertyName("index")]
	public int Index { get; init; }

	[JsonPropertyName("column_count")]
	public int ColumnCount { get; init; }

	[JsonPropertyName("row_count")]
	public int RowCount { get; init; }

	[JsonPropertyName("truncated")]
	public bool Truncated { get; init; }

	[JsonPropertyName("rows")]
	public JsonElement? Rows { get; init; }
}

public sealed class DBExecuteDataDTO {
	[JsonPropertyName("database_profile")]
	public string? DatabaseProfile { get; init; }

	[JsonPropertyName("result_sets")]
	public List<DBExecuteResultSetDTO>? ResultSets { get; init; }

	[JsonPropertyName("result_set_count")]
	public int ResultSetCount { get; init; }

	[JsonPropertyName("row_count")]
	public int RowCount { get; init; }

	[JsonPropertyName("affected_rows")]
	public int AffectedRows { get; init; }

	[JsonPropertyName("truncated")]
	public bool Truncated { get; init; }

	[JsonPropertyName("max_rows")]
	public int MaxRows { get; init; }
}

public static class Program {
	public static async Task Main() {
		try {
			using var apiClient = new HTTPSAPIGatewayClient(
				new Uri("https://lh.incorrigo.co.uk/public/"),
				new HTTPSAPIGatewayClientOptions {
					APIPath = "api.php",
				}
			);

			var status = await apiClient.GetSYSTEMSTATUSAsync();
			Console.WriteLine($"STATUS: online={status.Online}, transport={status.Transport}, auth={status.Authentication}");

			var link = await apiClient.OpenLINKAsync("DESKTOP001", "your-client-secret");
			Console.WriteLine($"AUTH.OPEN_LINK: opened={link.Opened}, display_name={link.DisplayName}");

			var profiles = await apiClient.GetDBProfilesAsync();
			Console.WriteLine($"DB.PROFILES: count={profiles.Count}");

			var read = await apiClient.ReadDBAsync("SELECT DATABASE() AS active_database");
			Console.WriteLine($"DB.READ: row_count={read.RowCount}, truncated={read.Truncated}");

			var echo = await apiClient.EchoLINKAsync("retry-me");
			Console.WriteLine($"LINK.ECHO: message={echo.Message}");

			var guid = apiClient.CurrentGUIDState;
			Console.WriteLine($"GUID: {guid?.GUID} / sequence={guid?.GUIDSequence}");
		} catch (APIGatewayException exception) {
			Console.WriteLine($"API ERROR: {exception.Message}");
			if (!string.IsNullOrWhiteSpace(exception.RawResponseBody)) {
				Console.WriteLine($"RAW RESPONSE: {exception.RawResponseBody}");
			}
		}
	}
}
