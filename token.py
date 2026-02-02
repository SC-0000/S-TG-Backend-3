import jwt, time

# Your LiveKit credentials
key    = "SKMeetDigitize_iGa59"      # API key
secret = "O9FcQRrIcaJ7aSOlcdS63h45ZPjGLW6FDOvveWqs4ibgTpWBlb9FB4CDFygTdCC9X3lm4Rfl6olq9m5p6h4O9p2PPw3ZTTeMNNSELWGx6mAowVparOw9k2K90h85Mu46Gdt5bCpI"        # API secret

now = int(time.time())

claims = {
  "iss": key,
  "sub": "wscat-tester-1",
  "nbf": now - 5,
  "exp": now + 600,  # valid 10 minutes
  "video": {
    "room": "test-room-1",
    "roomJoin": True,
    "roomCreate": True,
    "canPublish": True,
    "canSubscribe": True
  }
}

token = jwt.encode(claims, secret, algorithm="HS256")

print(token)
