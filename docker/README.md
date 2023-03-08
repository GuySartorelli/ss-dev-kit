# Main dockerfile

This is the overarching docker file which has no per-environment or per-user data at all.
It will be the same for everyone all the time, pulled directly from either github (the same way COW is) or from dockerhub.

For local development, build an image in this directory and name it `silverstripe/dev-starter-kit`

```bash
docker build -t silverstripe/dev-starter-kit .
```
