#!/bin/bash

# API Endpoint Testing Script
# Tests all API endpoints with sample requests

API_BASE="http://localhost/api/v1"
EMAIL="blake@dbest.com"
PASSWORD="password123"  # Change this to actual password

echo "========================================"
echo "   TimeClock API Endpoint Tests"
echo "========================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: Branding (No auth required)
echo -e "${YELLOW}Test 1: GET /api/v1/branding${NC}"
echo "---"
curl -s -X GET "$API_BASE/branding" | jq '.'
echo ""
echo ""

# Test 2: Login
echo -e "${YELLOW}Test 2: POST /api/v1/auth/login${NC}"
echo "---"
LOGIN_RESPONSE=$(curl -s -X POST "$API_BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")

echo "$LOGIN_RESPONSE" | jq '.'

# Extract tokens
ACCESS_TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.access_token // empty')
REFRESH_TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.refresh_token // empty')

if [ -z "$ACCESS_TOKEN" ]; then
    echo -e "${RED}❌ Login failed! Cannot continue with authenticated tests.${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Login successful!${NC}"
echo "Access Token: ${ACCESS_TOKEN:0:50}..."
echo ""
echo ""

# Test 3: Get User Profile
echo -e "${YELLOW}Test 3: GET /api/v1/user/profile${NC}"
echo "---"
curl -s -X GET "$API_BASE/user/profile" \
  -H "Authorization: Bearer $ACCESS_TOKEN" | jq '.'
echo ""
echo ""

# Test 4: Get My Shifts
echo -e "${YELLOW}Test 4: GET /api/v1/shifts/my${NC}"
echo "---"
curl -s -X GET "$API_BASE/shifts/my" \
  -H "Authorization: Bearer $ACCESS_TOKEN" | jq '.'
echo ""
echo ""

# Test 5: Get Clock Status
echo -e "${YELLOW}Test 5: GET /api/v1/clock/status${NC}"
echo "---"
CLOCK_STATUS=$(curl -s -X GET "$API_BASE/clock/status" \
  -H "Authorization: Bearer $ACCESS_TOKEN")
echo "$CLOCK_STATUS" | jq '.'

CLOCKED_IN=$(echo "$CLOCK_STATUS" | jq -r '.data.clocked_in')
echo ""
echo ""

# Test 6: Clock In (only if not already clocked in)
if [ "$CLOCKED_IN" = "false" ]; then
    echo -e "${YELLOW}Test 6: POST /api/v1/clock/in${NC}"
    echo "---"
    curl -s -X POST "$API_BASE/clock/in" \
      -H "Authorization: Bearer $ACCESS_TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"latitude":40.7128,"longitude":-74.0060,"notes":"API test clock in"}' | jq '.'
    echo -e "${GREEN}✅ Clocked in${NC}"
    echo ""
    echo ""

    # Sleep for a second
    sleep 2

    # Test 7: Clock Out
    echo -e "${YELLOW}Test 7: POST /api/v1/clock/out${NC}"
    echo "---"
    curl -s -X POST "$API_BASE/clock/out" \
      -H "Authorization: Bearer $ACCESS_TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"latitude":40.7128,"longitude":-74.0060,"notes":"API test clock out"}' | jq '.'
    echo -e "${GREEN}✅ Clocked out${NC}"
    echo ""
    echo ""
else
    echo -e "${YELLOW}Test 6: POST /api/v1/clock/in${NC}"
    echo "---"
    echo -e "${YELLOW}⚠️  Skipping clock in test - already clocked in${NC}"
    echo ""
    echo ""
fi

# Test 8: Get Clock History
echo -e "${YELLOW}Test 8: GET /api/v1/clock/history${NC}"
echo "---"
curl -s -X GET "$API_BASE/clock/history?limit=5" \
  -H "Authorization: Bearer $ACCESS_TOKEN" | jq '.'
echo ""
echo ""

# Test 9: Update Profile
echo -e "${YELLOW}Test 9: PUT /api/v1/user/update${NC}"
echo "---"
curl -s -X PUT "$API_BASE/user/update" \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone":"+1234567890"}' | jq '.'
echo ""
echo ""

# Test 10: Refresh Token
echo -e "${YELLOW}Test 10: POST /api/v1/auth/refresh${NC}"
echo "---"
REFRESH_RESPONSE=$(curl -s -X POST "$API_BASE/auth/refresh" \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"$REFRESH_TOKEN\"}")
echo "$REFRESH_RESPONSE" | jq '.'

NEW_ACCESS_TOKEN=$(echo "$REFRESH_RESPONSE" | jq -r '.data.access_token // empty')
if [ -n "$NEW_ACCESS_TOKEN" ]; then
    echo -e "${GREEN}✅ Token refreshed successfully${NC}"
fi
echo ""
echo ""

# Test 11: Logout
echo -e "${YELLOW}Test 11: POST /api/v1/auth/logout${NC}"
echo "---"
curl -s -X POST "$API_BASE/auth/logout" \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"$REFRESH_TOKEN\"}" | jq '.'
echo ""
echo ""

echo "========================================"
echo "   All Tests Complete!"
echo "========================================"
