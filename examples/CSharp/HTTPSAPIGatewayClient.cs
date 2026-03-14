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

public sealed class HTTPSAPIGatewayClient : IDisposable {
	private static readonly JsonSerializerOptions JSON_OPTIONS = new(JsonSerializerDefaults.Web) {
		PropertyNameCaseInsensitive = true,
		WriteIndented = true,
		DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
	};

	private readonly HTTPClient _httpClient;
	private APIGUIDStateDTO? _currentGUIDState;

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
		return SendActionAsync<SystemStatusDTO>("SYSTEM.STATUS", null, requiresGUID: false, cancellationToken);
	}

	public async Task<AUTHOpenLinkDataDTO> OpenLINKAsync(string clientID, string clientSecret, CancellationToken cancellationToken = default) {
		var response = await SendActionAsync<AUTHOpenLinkDataDTO>(
			"AUTH.OPEN_LINK",
			new AUTHOpenLinkParamsDTO {
				ClientID = clientID,
				ClientSecret = clientSecret,
			},
			requiresGUID: false,
			cancellationToken
		);

		if (_currentGUIDState is null) {
			throw new InvalidOperationException("Gateway did not return a GUID after AUTH.OPEN_LINK.");
		}

		return response;
	}

	public Task<LINKEchoDataDTO> EchoLINKAsync(string nonce, CancellationToken cancellationToken = default) {
		return SendActionAsync<LINKEchoDataDTO>(
			"LINK.ECHO",
			new LINKEchoParamsDTO {
				Nonce = nonce,
			},
			requiresGUID: true,
			cancellationToken
		);
	}

	public Task<LINKInfoDataDTO> GetLINKINFOAsync(CancellationToken cancellationToken = default) {
		return SendActionAsync<LINKInfoDataDTO>("LINK.INFO", null, requiresGUID: true, cancellationToken);
	}

	private async Task<T> SendActionAsync<T>(string action, object? parameters, bool requiresGUID, CancellationToken cancellationToken) {
		var guidSnapshot = requiresGUID
			? _currentGUIDState?.GUID ?? throw new InvalidOperationException("Call OpenLINKAsync before sending authenticated actions.")
			: null;

		var envelope = new APIRequestEnvelopeDTO {
			Action = action,
			Params = parameters,
			GUID = guidSnapshot,
		};

		try {
			return await SendEnvelopeCoreAsync<T>(envelope, cancellationToken);
		} catch (HttpRequestException) when (guidSnapshot is not null) {
			return await SendEnvelopeCoreAsync<T>(envelope, cancellationToken);
		} catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested && guidSnapshot is not null) {
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

		var guidState = ExtractGUIDState(apiResponse);
		if (guidState is not null) {
			_currentGUIDState = guidState;
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

	private static APIGUIDStateDTO? ExtractGUIDState<T>(APIResponseEnvelopeDTO<T> apiResponse) {
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

	[JsonPropertyName("guid")]
	public string? GUID { get; init; }
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

	[JsonPropertyName("guid")]
	public string? GUID { get; init; }

	[JsonPropertyName("guid_sequence")]
	public int? GUIDSequence { get; init; }

	[JsonPropertyName("guid_expires_at")]
	public string? GUIDExpiresAt { get; init; }

	[JsonPropertyName("error")]
	public APIErrorDTO? Error { get; init; }
}

public sealed class APIErrorDTO {
	[JsonPropertyName("code")]
	public string? Code { get; init; }

	[JsonPropertyName("message")]
	public string? Message { get; init; }
}

public sealed class APIGUIDStateDTO {
	public required string GUID { get; init; }

	public int GUIDSequence { get; init; }

	public required string GUIDExpiresAt { get; init; }
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
	public Dictionary<string, string>? Echo { get; init; }

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

public static class Program {
	public static async Task Main() {
		using var apiClient = new HTTPSAPIGatewayClient(new Uri("https://lh.incorrigo.co.uk/"));

		var status = await apiClient.GetSYSTEMSTATUSAsync();
		Console.WriteLine($"STATUS: {status.Online} / {status.Message}");

		var link = await apiClient.OpenLINKAsync("DESKTOP001", "your-client-secret");
		Console.WriteLine($"LINK OPENED: {link.Opened}");

		var echo = await apiClient.EchoLINKAsync("retry-me");
		Console.WriteLine($"ECHO: {echo.Message}");

		var info = await apiClient.GetLINKINFOAsync();
		Console.WriteLine($"INFO: {info.Message} ({info.Mode})");
	}
}
