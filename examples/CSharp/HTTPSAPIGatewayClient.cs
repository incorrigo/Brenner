using System;
using System.Collections.Generic;
using System.Net.Http.Headers;
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

public sealed class HTTPSAPIGatewayClient : IDisposable {
	private static readonly JsonSerializerOptions JSON_OPTIONS = new(JsonSerializerDefaults.Web) {
		PropertyNameCaseInsensitive = true,
		WriteIndented = true,
	};

	private readonly HTTPClient _httpClient;
	private APILinkSessionDTO? _currentSession;

	public HTTPSAPIGatewayClient(Uri baseAddress) {
		if (baseAddress.Scheme != Uri.UriSchemeHttps) {
			throw new ArgumentException("Base address must use HTTPS.", nameof(baseAddress));
		}

		_httpClient = new HTTPClient {
			BaseAddress = baseAddress,
			Timeout = TimeSpan.FromSeconds(30),
		};
	}

	public Task<SystemStatusDTO> GetSYSTEMSTATUSAsync(CancellationToken cancellationToken = default) {
		return SendActionAsync<SystemStatusDTO>("SYSTEM.STATUS", null, requiresLink: false, cancellationToken);
	}

	public async Task<AUTHOpenLinkDataDTO> OpenLINKAsync(string clientID, string clientSecret, CancellationToken cancellationToken = default) {
		var response = await SendActionAsync<AUTHOpenLinkDataDTO>(
			"AUTH.OPEN_LINK",
			new AUTHOpenLinkParamsDTO {
				ClientID = clientID,
				ClientSecret = clientSecret,
			},
			requiresLink: false,
			cancellationToken
		);

		if (_currentSession is null) {
			throw new InvalidOperationException("Gateway did not return a session after AUTH.OPEN_LINK.");
		}

		return response;
	}

	public Task<LINKPingDataDTO> PingLINKAsync(CancellationToken cancellationToken = default) {
		return SendActionAsync<LINKPingDataDTO>("LINK.PING", null, requiresLink: true, cancellationToken);
	}

	public Task<List<UserRecordDTO>> GetUSERSLISTAsync(int limit, CancellationToken cancellationToken = default) {
		return SendActionAsync<List<UserRecordDTO>>(
			"USERS.LIST",
			new USERSListParamsDTO {
				Limit = limit,
			},
			requiresLink: true,
			cancellationToken
		);
	}

	public Task<UserRecordDTO?> GetUSERBYIDAsync(int id, CancellationToken cancellationToken = default) {
		return SendActionAsync<UserRecordDTO?>(
			"USER.BY_ID",
			new USERByIDParamsDTO {
				ID = id,
			},
			requiresLink: true,
			cancellationToken
		);
	}

	private async Task<T> SendActionAsync<T>(string action, object? parameters, bool requiresLink, CancellationToken cancellationToken) {
		var sessionSnapshot = requiresLink
			? CloneSession(_currentSession ?? throw new InvalidOperationException("Call OpenLINKAsync before sending authenticated actions."))
			: null;

		var envelope = new APIRequestEnvelopeDTO {
			Action = action,
			Params = parameters,
			Session = sessionSnapshot,
		};

		try {
			return await SendEnvelopeCoreAsync<T>(envelope, cancellationToken);
		} catch (HttpRequestException) when (sessionSnapshot is not null) {
			return await SendEnvelopeCoreAsync<T>(envelope, cancellationToken);
		} catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested && sessionSnapshot is not null) {
			return await SendEnvelopeCoreAsync<T>(envelope, cancellationToken);
		}
	}

	private async Task<T> SendEnvelopeCoreAsync<T>(APIRequestEnvelopeDTO envelope, CancellationToken cancellationToken) {
		using var request = new HTTPRequestMessage(HTTPMethod.Post, "api.php");
		request.Headers.Add("X-Request-ID", Guid.NewGuid().ToString("N"));
		request.Content = new HTTPStringContent(JsonSerializer.Serialize(envelope, JSON_OPTIONS), Encoding.UTF8, "application/json");

		using HTTPResponseMessage response = await _httpClient.SendAsync(request, cancellationToken);
		var body = await response.Content.ReadAsStringAsync(cancellationToken);
		var apiResponse = JsonSerializer.Deserialize<APIResponseEnvelopeDTO<T>>(body, JSON_OPTIONS);

		if (apiResponse is null) {
			throw new InvalidOperationException("API response could not be deserialized.");
		}

		if (apiResponse.Session is not null) {
			_currentSession = apiResponse.Session;
		}

		if (!response.IsSuccessStatusCode || !apiResponse.OK) {
			throw new APIGatewayException(
				apiResponse.Error?.Code ?? "HTTP_ERROR",
				apiResponse.Error?.Message ?? response.ReasonPhrase ?? "Unknown API error.",
				apiResponse.RequestID
			);
		}

		return apiResponse.Data!;
	}

	private static APILinkSessionDTO CloneSession(APILinkSessionDTO session) => new() {
		SessionID = session.SessionID,
		CommandGUID = session.CommandGUID,
		Sequence = session.Sequence,
		ExpiresAt = session.ExpiresAt,
	};

	public void Dispose() {
		_httpClient.Dispose();
	}
}

public sealed class APIGatewayException : Exception {
	public APIGatewayException(string errorCode, string message, string? requestID)
		: base($"{errorCode}: {message} (request_id={requestID})") {
		ErrorCode = errorCode;
		RequestID = requestID;
	}

	public string ErrorCode { get; }

	public string? RequestID { get; }
}

public sealed class APIRequestEnvelopeDTO {
	[JsonPropertyName("action")]
	public required string Action { get; init; }

	[JsonPropertyName("params")]
	public object? Params { get; init; }

	[JsonPropertyName("session")]
	public APILinkSessionDTO? Session { get; init; }
}

public sealed class APIResponseEnvelopeDTO<T> {
	[JsonPropertyName("ok")]
	public bool OK { get; init; }

	[JsonPropertyName("request_id")]
	public string? RequestID { get; init; }

	[JsonPropertyName("action")]
	public string? Action { get; init; }

	[JsonPropertyName("data")]
	public T? Data { get; init; }

	[JsonPropertyName("session")]
	public APILinkSessionDTO? Session { get; init; }

	[JsonPropertyName("error")]
	public APIErrorDTO? Error { get; init; }
}

public sealed class APIErrorDTO {
	[JsonPropertyName("code")]
	public string? Code { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }
}

public sealed class APILinkSessionDTO {
	[JsonPropertyName("session_id")]
	public required string SessionID { get; init; }

	[JsonPropertyName("command_guid")]
	public required string CommandGUID { get; init; }

	[JsonPropertyName("sequence")]
	public int Sequence { get; init; }

	[JsonPropertyName("expires_at")]
	public required string ExpiresAt { get; init; }
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

public sealed class LINKPingDataDTO {
	[JsonPropertyName("authenticated")]
	public bool Authenticated { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }
}

public sealed class SystemStatusDTO {
	[JsonPropertyName("online")]
	public bool Online { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }

	[JsonPropertyName("transport")]
	public string? Transport { get; init; }

	[JsonPropertyName("authentication")]
	public string? Authentication { get; init; }
}

public sealed class USERSListParamsDTO {
	[JsonPropertyName("limit")]
	public int Limit { get; init; }
}

public sealed class USERByIDParamsDTO {
	[JsonPropertyName("id")]
	public int ID { get; init; }
}

public sealed class UserRecordDTO {
	[JsonPropertyName("id")]
	public int ID { get; init; }

	[JsonPropertyName("name")]
	public string? Name { get; init; }

	[JsonPropertyName("email")]
	public string? Email { get; init; }
}

public static class Program {
	public static async Task Main() {
		using var apiClient = new HTTPSAPIGatewayClient(new Uri("https://lh.incorrigo.co.uk/"));

		var status = await apiClient.GetSYSTEMSTATUSAsync();
		Console.WriteLine($"STATUS: {status.Online} / {status.Message}");

		var link = await apiClient.OpenLINKAsync("DESKTOP001", "your-client-secret");
		Console.WriteLine($"LINK OPENED: {link.Opened}");

		var ping = await apiClient.PingLINKAsync();
		Console.WriteLine($"PING: {ping.Message}");

		var users = await apiClient.GetUSERSLISTAsync(10);
		Console.WriteLine($"USERS RETURNED: {users.Count}");

		var user = await apiClient.GetUSERBYIDAsync(4);
		Console.WriteLine($"USER: {user?.Name}");
	}
}
