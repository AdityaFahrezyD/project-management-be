from decimal import ROUND_HALF_UP, Decimal

ZERO = Decimal(0)
RESULT_SCALE = Decimal("0.00000001")
RESULT_LIMIT = Decimal("10000000000000000")


class AnalysisError(ValueError):
    """An analysis cannot produce a valid, persistable result."""


def rounded(value: Decimal) -> Decimal:
    result = value.quantize(RESULT_SCALE, rounding=ROUND_HALF_UP)
    if abs(result) >= RESULT_LIMIT:
        raise AnalysisError("Calculation exceeds the DECIMAL(24, 8) result storage limit.")
    return result
