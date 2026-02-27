package logger

import (
	"log/slog"
	"os"
	"time"
)

// LogForwarder is implemented by the WebSocket client to forward log entries to the API.
type LogForwarder interface {
	ForwardLog(level, message string, context map[string]interface{})
}

// levelVar is the dynamic log level variable used globally.
var levelVar = new(slog.LevelVar)

// Setup initializes the global logger with the given log level.
func Setup(level string) {
	levelVar.Set(parseLevel(level))
	handler := slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: levelVar})
	slog.SetDefault(slog.New(handler))
}

// SetLevel dynamically changes the log level without recreating the logger.
func SetLevel(level string) {
	levelVar.Set(parseLevel(level))
	slog.Info("Log level changed", "level", level)
}

// GetLevelVar returns the shared LevelVar for use in handler options.
func GetLevelVar() *slog.LevelVar {
	return levelVar
}

// Log emits a log entry and optionally forwards it to the API via the provided forwarder.
func Log(forwarder LogForwarder, level slog.Level, message string, args ...any) {
	// Emit locally
	switch level {
	case slog.LevelDebug:
		slog.Debug(message, args...)
	case slog.LevelWarn:
		slog.Warn(message, args...)
	case slog.LevelError:
		slog.Error(message, args...)
	default:
		slog.Info(message, args...)
	}

	// Forward to API if forwarder is available
	if forwarder != nil {
		ctx := argsToContext(args...)
		forwarder.ForwardLog(levelToString(level), message, ctx)
	}
}

// argsToContext converts slog args (key-value pairs or Attrs) to a map.
func argsToContext(args ...any) map[string]interface{} {
	ctx := make(map[string]interface{})
	ctx["timestamp"] = time.Now().UTC().Format(time.RFC3339)

	for i := 0; i+1 < len(args); i += 2 {
		if key, ok := args[i].(string); ok {
			ctx[key] = args[i+1]
		}
	}
	return ctx
}

func levelToString(level slog.Level) string {
	switch level {
	case slog.LevelDebug:
		return "debug"
	case slog.LevelWarn:
		return "warn"
	case slog.LevelError:
		return "error"
	default:
		return "info"
	}
}

func parseLevel(level string) slog.Level {
	switch level {
	case "debug":
		return slog.LevelDebug
	case "warn":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}
