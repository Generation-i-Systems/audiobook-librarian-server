---
trigger: always_on
---

all classes that use DocumentStoreServiceInterface should return arrays for all data not specific things like BSONArray or BSONDocument
user authentication is handled by DocumentstoreUser NOT models/User
