#!/bin/bash

echo "Testing the /me endpoint..."
echo ""
echo "Step 1: Login to get a token"
echo "================================"

# Login to get a token (replace with your actual credentials)
LOGIN_RESPONSE=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "zaid.a@weworkpeople.com",
    "password": "password",
    "device_name": "test"
  }')

echo "$LOGIN_RESPONSE" | jq '.'

# Extract token
TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.token // empty')

if [ -z "$TOKEN" ]; then
  echo ""
  echo "❌ Failed to get token. Please check your credentials."
  exit 1
fi

echo ""
echo "✅ Got token: ${TOKEN:0:20}..."
echo ""
echo "Step 2: Test /me endpoint"
echo "================================"

# Test /me endpoint with the token
curl -s -X GET http://localhost:8000/api/v1/auth/me \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.'

echo ""
echo "✅ Test complete!"
